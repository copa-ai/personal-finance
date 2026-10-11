"""Orquestador: orden de las cadenas, cooldown, caché stale, errores parciales, validación."""

import json
import unittest

import _support
from _support import (BINANCE, CG, ECB, KRAKEN, YQ1, YQ2, FakeTransport, add_ecb, add_yahoo,
                      fixture, fixture_bytes, json_response, make_market, status)
from market import ServiceError


def ok_kraken(transport):
    transport.add(KRAKEN, "/0/public/Ticker", fixture("kraken_ticker.json"))


class CryptoChainTests(unittest.TestCase):
    def test_kraken_first(self):
        market, transport, _ = make_market()
        ok_kraken(transport)
        out = market.quotes("crypto:BTC-USD,crypto:ETH-EUR")
        self.assertEqual(out["errors"], [])
        self.assertEqual([q["source"] for q in out["quotes"]], ["kraken", "kraken"])
        self.assertEqual(transport.hosts(), [KRAKEN])  # un único lote
        btc = out["quotes"][0]
        self.assertEqual(set(btc), {"symbol", "name", "price", "currency", "previous_close", "change",
                                    "change_pct", "as_of", "source", "stale"})
        self.assertEqual(btc["change"], round(82969.0 - 82912.7, 6))
        self.assertEqual(btc["change_pct"], round((82969.0 - 82912.7) / 82912.7 * 100, 4))
        self.assertFalse(btc["stale"])

    def test_fallback_order(self):
        market, transport, _ = make_market()
        transport.add(KRAKEN, "/0/public/Ticker", status(503, b"down"))
        transport.add(CG, "/api/v3/simple/price", status(500))
        transport.add(BINANCE, "/api/v3/ticker/24hr", fixture("binance_24hr.json"))
        out = market.quotes("crypto:BTC-USD")
        self.assertEqual(out["quotes"][0]["source"], "binance")
        self.assertEqual(transport.hosts(), [KRAKEN, CG, BINANCE])

    def test_yahoo_last_resort(self):
        market, transport, _ = make_market()
        add_yahoo(transport, "BTC-USD", fixture("yahoo_chart_quote_gspc.json"))
        out = market.quotes("crypto:BTC-USD")  # Kraken, CoinGecko y Binance sin ruta = red caída
        self.assertEqual(out["quotes"][0]["source"], "yahoo")
        self.assertEqual(transport.hosts(), [KRAKEN, CG, BINANCE, YQ1])

    def test_429_cooldown_then_next_provider(self):
        market, transport, clock = make_market(env={"MD_QUOTE_TTL": "30"})
        transport.add(KRAKEN, "/0/public/Ticker", status(429))
        transport.add(CG, "/api/v3/simple/price", fixture("coingecko_simple_price.json"))
        out = market.quotes("crypto:BTC-USD")
        self.assertEqual(out["quotes"][0]["source"], "coingecko")
        self.assertEqual(market.health()["providers"]["kraken"], "cooldown")
        clock.advance(market.quote_ttl + 1)
        market.quotes("crypto:BTC-USD")
        self.assertEqual(len(transport.calls_to(KRAKEN)), 1)  # en cooldown no se le llama
        clock.advance(30)
        self.assertEqual(market.health()["providers"]["kraken"], "ok")


class StaleCacheTests(unittest.TestCase):
    def test_fresh_cache_hit(self):
        market, transport, clock = make_market()
        ok_kraken(transport)
        market.quotes("crypto:BTC-USD")
        clock.advance(market.quote_ttl - 1)
        market.quotes("crypto:BTC-USD")
        self.assertEqual(len(transport.calls), 1)

    def test_stale_when_all_fail(self):
        market, transport, clock = make_market()
        add_yahoo(transport, "^GSPC", [fixture("yahoo_chart_quote_gspc.json"), status(503)])
        first = market.quotes("index:^GSPC")["quotes"][0]
        clock.advance(30 * 86400)  # cualquier antigüedad
        out = market.quotes("index:^GSPC")
        self.assertEqual(out["errors"], [])
        stale = out["quotes"][0]
        self.assertTrue(stale["stale"])
        self.assertEqual({k: v for k, v in stale.items() if k != "stale"},
                         {k: v for k, v in first.items() if k != "stale"})

    def test_error_without_cache(self):
        market, transport, _ = make_market()
        add_yahoo(transport, "^GSPC", status(503))
        out = market.quotes("index:^GSPC")
        self.assertEqual(out, {"quotes": [], "errors": [
            {"symbol": "index:^GSPC", "error": "proveedores no disponibles"}]})


class PartialErrorTests(unittest.TestCase):
    def test_mixed(self):
        market, transport, _ = make_market()
        ok_kraken(transport)
        add_ecb(transport)
        add_yahoo(transport, "^GSPC", fixture("yahoo_chart_quote_gspc.json"))
        add_yahoo(transport, "XXXX", fixture("yahoo_chart_not_found.json", 404))
        out = market.quotes("crypto:BTC-USD,index:^GSPC,stock:XXXX,fx:EURUSD")
        self.assertEqual([q["symbol"] for q in out["quotes"]],
                         ["crypto:BTC-USD", "index:^GSPC", "fx:EURUSD"])
        self.assertEqual(out["errors"], [{"symbol": "stock:XXXX", "error": "no encontrado"}])

    def test_symbol_validation(self):
        market, _, _ = make_market()
        for raw in [None, "", "stock:AAPL,nope", ",".join(f"stock:A{i}" for i in range(51))]:
            with self.subTest(raw=raw), self.assertRaises(ServiceError) as ctx:
                market.quotes(raw)
            self.assertEqual(ctx.exception.status, 400)

    def test_duplicates_collapsed(self):
        market, transport, _ = make_market()
        ok_kraken(transport)
        out = market.quotes("crypto:BTC-USD,crypto:BTC-USD")
        self.assertEqual(len(out["quotes"]), 1)


class FxTests(unittest.TestCase):
    def test_cross_rates_both_directions(self):
        market, transport, _ = make_market()
        add_ecb(transport)
        out = market.quotes("fx:EURUSD,fx:USDEUR,fx:GBPJPY")
        prices = {q["symbol"]: q for q in out["quotes"]}
        self.assertEqual(prices["fx:EURUSD"]["price"], 1.1206)
        self.assertAlmostEqual(prices["fx:USDEUR"]["price"], 1 / 1.1206, places=7)
        self.assertAlmostEqual(prices["fx:GBPJPY"]["price"], 177.34 / 0.84763, places=3)
        self.assertEqual(prices["fx:EURUSD"]["previous_close"], 1.1186)
        self.assertEqual({q["source"] for q in out["quotes"]}, {"ecb"})

    def test_fx_falls_back_to_yahoo(self):
        market, transport, _ = make_market()
        add_ecb(transport)
        add_yahoo(transport, "USDARS=X", fixture("yahoo_chart_quote_gspc.json"))
        q = market.quotes("fx:USDARS")["quotes"][0]
        self.assertEqual(q["source"], "yahoo")
        self.assertIn("/v8/finance/chart/USDARS%3DX", transport.calls[-1]["url"])

    def test_fx_table(self):
        market, transport, _ = make_market()
        add_ecb(transport)
        table = market.fx()
        self.assertEqual((table["date"], table["base"], table["source"]), ("2026-10-09", "EUR", "ecb"))
        self.assertEqual(table["rates"]["USD"], 1.1206)

    def test_fx_table_unavailable(self):
        market, transport, _ = make_market()
        transport.add(ECB, "/stats/eurofxref/eurofxref-daily.xml", status(500))
        with self.assertRaises(ServiceError) as ctx:
            market.fx()
        self.assertEqual(ctx.exception.status, 502)


class TraditionalChainTests(unittest.TestCase):
    def test_keyed_providers_after_yahoo(self):
        env = {"MD_TWELVEDATA_KEY": "td", "MD_FINNHUB_KEY": "fh", "MD_ALPHAVANTAGE_KEY": "av"}
        market, transport, _ = make_market(env=env)
        add_yahoo(transport, "AAPL", status(429))
        transport.add("api.twelvedata.com", "/quote", fixture("twelvedata_error_limit.json"))
        transport.add("finnhub.io", "/api/v1/quote", status(500))
        transport.add("www.alphavantage.co", "/query", fixture("alphavantage_quote_aapl.json"))
        q = market.quotes("stock:AAPL")["quotes"][0]
        self.assertEqual(q["source"], "alphavantage")
        self.assertEqual(transport.hosts(), [YQ1, "api.twelvedata.com", "finnhub.io", "www.alphavantage.co"])
        self.assertEqual(market.health()["providers"]["yahoo"], "cooldown")


class HistoryTests(unittest.TestCase):
    def test_validation(self):
        market, _, _ = make_market()
        bad = [(None, "1mo", "1d"), ("stock:aapl", "1mo", "1d"), ("stock:AAPL", "2y", "1d"),
               ("stock:AAPL", "1mo", "5m"), ("stock:AAPL", "5y", "1h"), ("stock:AAPL", "max", "1h"),
               ("stock:AAPL", "1d", "1wk"), ("stock:AAPL", "1mo", "1mo")]
        for args in bad:
            with self.subTest(args=args), self.assertRaises(ServiceError) as ctx:
                market.history(*args)
            self.assertEqual(ctx.exception.status, 400)

    def test_yahoo_history_and_cache(self):
        market, transport, clock = make_market()
        add_yahoo(transport, "^GSPC", fixture("yahoo_chart_history_gspc.json"))
        h = market.history("index:^GSPC", "5d", "1d")
        self.assertEqual((h["symbol"], h["currency"], h["source"]), ("index:^GSPC", "USD", "yahoo"))
        self.assertEqual(len(h["candles"]), 5)
        self.assertEqual(transport.calls[0]["query"], {"range": "5d", "interval": "1d", "includePrePost": "false"})
        clock.advance(5 * 3600)
        market.history("index:^GSPC", "5d", "1d")
        self.assertEqual(len(transport.calls), 1)

    def test_crypto_history_kraken_then_yahoo(self):
        market, transport, _ = make_market()
        transport.add(KRAKEN, "/0/public/OHLC", fixture("kraken_ohlc.json"))
        add_yahoo(transport, "BTC-USD", fixture("yahoo_chart_history_gspc.json"))
        self.assertEqual(market.history("crypto:BTC-USD", "5d", "1d")["source"], "kraken")
        # `max` no cabe en las 720 velas de Kraken: va directo a Yahoo.
        self.assertEqual(market.history("crypto:BTC-USD", "max", "1d")["source"], "yahoo")

    def test_not_found_and_unavailable(self):
        market, transport, _ = make_market()
        add_yahoo(transport, "XXXX", fixture("yahoo_chart_not_found.json", 404))
        with self.assertRaises(ServiceError) as ctx:
            market.history("stock:XXXX", "1mo", "1d")
        self.assertEqual((ctx.exception.status, ctx.exception.message), (404, "no encontrado"))
        with self.assertRaises(ServiceError) as ctx:
            market.history("stock:YYYY", "1mo", "1d")
        self.assertEqual(ctx.exception.status, 502)


class SearchTests(unittest.TestCase):
    def test_merge_and_dedupe(self):
        market, transport, _ = make_market()
        transport.add(YQ1, "/v1/finance/search", fixture("yahoo_search_apple.json"))
        transport.add(YQ2, "/v1/finance/search", fixture("yahoo_search_apple.json"))
        transport.add(CG, "/api/v3/search", fixture("coingecko_search_apple.json"))
        out = market.search("apple")
        symbols_ = [r["symbol"] for r in out["results"]]
        self.assertEqual(symbols_[:4], ["stock:AAPL", "stock:APC.DE", "etf:AAPW", "crypto:AAPLX-USD"])
        self.assertEqual(len(symbols_), len(set(symbols_)))  # AAPLX-USD llega por ambos
        self.assertIn("crypto:AAPL-USD", symbols_)
        self.assertLessEqual(len(symbols_), 20)
        market.search("Apple")  # cacheado (clave en minúsculas)
        self.assertEqual(len(transport.calls), 2)

    def test_one_provider_down(self):
        market, transport, _ = make_market()
        transport.add(CG, "/api/v3/search", fixture("coingecko_search_apple.json"))
        out = market.search("apple")
        self.assertEqual({r["type"] for r in out["results"]}, {"crypto"})

    def test_all_down(self):
        market, _, _ = make_market()
        with self.assertRaises(ServiceError) as ctx:
            market.search("apple")
        self.assertEqual(ctx.exception.status, 502)

    def test_validation(self):
        market, _, _ = make_market()
        for q in [None, "", "   ", "a" * 65, "ap\nple"]:
            with self.subTest(q=q), self.assertRaises(ServiceError):
                market.search(q)

    def test_max_results(self):
        market, transport, _ = make_market()
        many = {"quotes": [{"symbol": f"T{i}", "quoteType": "EQUITY", "shortname": f"T{i}"} for i in range(30)]}
        transport.add(YQ1, "/v1/finance/search", json_response(many))
        transport.add(CG, "/api/v3/search", fixture("coingecko_search_apple.json"))
        self.assertEqual(len(market.search("t")["results"]), 20)


class NotifyTests(unittest.TestCase):
    def test_not_configured(self):
        market, _, _ = make_market()
        with self.assertRaises(ServiceError) as ctx:
            market.notify({"message": "hola"})
        self.assertEqual((ctx.exception.status, ctx.exception.message), (503, "ntfy no configurado"))

    def test_relay(self):
        env = {"MD_NTFY_URL": "https://ntfy.example.org/finanzas", "MD_NTFY_TOKEN": "tk_abc"}
        market, transport, _ = make_market(env=env)
        transport.add("ntfy.example.org", "/", json_response({"id": "x", "event": "message"}))
        out = market.notify({"title": "Alerta de precio", "message": "BTC superó 90 000 €",
                             "priority": "high", "tags": ["chart_with_upwards_trend"]})
        self.assertEqual(out, {"ok": True})
        call = transport.calls[0]
        self.assertEqual(call["method"], "POST")
        self.assertEqual(call["headers"]["Authorization"], "Bearer tk_abc")
        self.assertEqual(json.loads(call["body"]), {
            "topic": "finanzas", "message": "BTC superó 90 000 €", "priority": 4,
            "title": "Alerta de precio", "tags": ["chart_with_upwards_trend"]})

    def test_validation(self):
        market, _, _ = make_market(env={"MD_NTFY_URL": "https://ntfy.sh/t"})
        for body in [None, [], {}, {"message": ""}, {"message": "x", "priority": "max"},
                     {"message": "x", "tags": "a"}, {"message": "x", "tags": ["a b"]},
                     {"message": "x", "title": 3}]:
            with self.subTest(body=body), self.assertRaises(ServiceError) as ctx:
                market.notify(body)
            self.assertEqual(ctx.exception.status, 400)


if __name__ == "__main__":
    unittest.main()
