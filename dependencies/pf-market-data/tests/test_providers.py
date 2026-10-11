"""Parsers de cada proveedor contra fixtures con la forma real de sus respuestas."""

import json
import unittest

import _support
from _support import (BINANCE, CG, ECB, KRAKEN, YQ1, YQ2, FakeTransport, add_ecb, add_yahoo,
                      fixture, fixture_bytes, json_response, make_market, status)
import symbols
from errors import NotFound, ProviderUnavailable, RateLimited
from providers import ecb as ecb_mod
from providers.base import normalize_currency
from providers.keyed import td_params
from providers.yahoo import Yahoo, canonical_from_yahoo

S = symbols.parse


def load(name):
    return json.loads(fixture_bytes(name))


class YahooTests(unittest.TestCase):
    def test_parse_quote(self):
        result = load("yahoo_chart_quote_gspc.json")["chart"]["result"][0]
        q = Yahoo.parse_quote(result, lambda: 0)
        self.assertEqual(q["price"], 6552.51)
        self.assertEqual(q["previous_close"], 6735.11)
        self.assertEqual(q["currency"], "USD")
        self.assertEqual(q["name"], "S&P 500")
        self.assertEqual(q["as_of"], "2026-10-08T20:00:00Z")
        self.assertEqual(q["source"], "yahoo")

    def test_parse_history_skips_null_close(self):
        result = load("yahoo_chart_history_gspc.json")["chart"]["result"][0]
        h = Yahoo.parse_history(result)
        self.assertEqual(h["currency"], "USD")
        self.assertEqual(len(h["candles"]), 5)
        first = h["candles"][0]
        self.assertEqual(set(first), {"t", "o", "h", "l", "c", "v"})
        self.assertEqual(first["c"], 6712.3)
        self.assertTrue(first["t"].endswith("Z"))

    def test_pence_normalized_to_gbp(self):
        result = {"meta": {"currency": "GBp", "regularMarketPrice": 812.4, "chartPreviousClose": 800.0,
                           "regularMarketTime": 1791489600, "shortName": "VUSA"}}
        q = Yahoo.parse_quote(result, lambda: 0)
        self.assertEqual((q["currency"], q["price"], q["previous_close"]), ("GBP", 8.124, 8.0))
        self.assertEqual(normalize_currency("EUR"), ("EUR", 1.0))

    def test_parse_search(self):
        results = Yahoo.parse_search(load("yahoo_search_apple.json"))
        self.assertEqual([r["symbol"] for r in results],
                         ["stock:AAPL", "stock:APC.DE", "etf:AAPW", "crypto:AAPLX-USD"])
        self.assertEqual(results[0], {"symbol": "stock:AAPL", "name": "Apple Inc.",
                                      "type": "stock", "exchange": "NASDAQ"})

    def test_canonical_mapping(self):
        self.assertEqual(canonical_from_yahoo("EURUSD=X", "CURRENCY"), "fx:EURUSD")
        self.assertEqual(canonical_from_yahoo("JPY=X", "CURRENCY"), "fx:USDJPY")
        self.assertEqual(canonical_from_yahoo("GC=F", "FUTURE"), "commodity:GC=F")
        self.assertEqual(canonical_from_yahoo("^IBEX", "INDEX"), "index:^IBEX")
        self.assertEqual(canonical_from_yahoo("0P0000XXXX.F", "MUTUALFUND"), "fund:0P0000XXXX.F")
        self.assertIsNone(canonical_from_yahoo("AAPL261016C00230000", "OPTION"))

    def test_hosts_alternate(self):
        market, transport, _ = make_market()
        add_yahoo(transport, "^GSPC", fixture("yahoo_chart_quote_gspc.json"))
        for _ in range(2):
            market.yahoo.quote(S("index:^GSPC"))
        self.assertEqual(transport.hosts(), [YQ1, YQ2])
        self.assertEqual(transport.calls[0]["query"]["range"], "1d")
        self.assertIn("Mozilla", transport.calls[0]["headers"]["User-Agent"])

    def test_crumb_flow_on_401(self):
        market, transport, _ = make_market()
        path = "/v8/finance/chart/AAPL"
        ok = fixture("yahoo_chart_quote_gspc.json")
        transport.add(YQ1, path, status(401, b'{"finance":{"error":{"code":"Unauthorized"}}}'))
        transport.add(YQ2, path, ok, crumb="abc.Crumb")
        transport.add("fc.yahoo.com", "/", status(404, headers=[
            ("Set-Cookie", "A3=d=AQABBC; Expires=Sun, 11 Oct 2027 10:00:00 GMT; Domain=.yahoo.com; Secure"),
            ("Set-Cookie", "A1=d=XYZ; Domain=.yahoo.com")]))
        transport.add(YQ2, "/v1/test/getcrumb", status(200, b"abc.Crumb"))
        q = market.yahoo.quote(S("stock:AAPL"))
        self.assertEqual(q["price"], 6552.51)
        last = transport.calls[-1]
        self.assertEqual(last["headers"]["Cookie"], "A3=d=AQABBC; A1=d=XYZ")
        self.assertEqual(last["query"]["crumb"], "abc.Crumb")

    def test_404_is_not_found(self):
        market, transport, _ = make_market()
        add_yahoo(transport, "ZZZZ", fixture("yahoo_chart_not_found.json", 404))
        with self.assertRaises(NotFound):
            market.yahoo.quote(S("stock:ZZZZ"))
        self.assertEqual(market.yahoo.status(), "ok")

    def test_429_cooldown(self):
        market, transport, clock = make_market()
        add_yahoo(transport, "AAPL", status(429, b"Too Many Requests"))
        with self.assertRaises(RateLimited):
            market.yahoo.quote(S("stock:AAPL"))
        self.assertEqual(market.yahoo.status(), "cooldown")
        with self.assertRaises(ProviderUnavailable):
            market.yahoo.quote(S("stock:AAPL"))
        self.assertEqual(len(transport.calls), 1)  # en cooldown no se llama
        clock.advance(61)
        self.assertEqual(market.yahoo.status(), "ok")


class KrakenTests(unittest.TestCase):
    def test_batch_ticker(self):
        market, transport, _ = make_market()
        transport.add(KRAKEN, "/0/public/Ticker", fixture("kraken_ticker.json"))
        found, errors = market.kraken.quotes([S("crypto:BTC-USD"), S("crypto:ETH-EUR")])
        self.assertEqual(errors, {})
        self.assertEqual(transport.calls[0]["query"]["pair"], "ETHEUR,XBTUSD")
        btc = found["crypto:BTC-USD"]
        self.assertEqual((btc["price"], btc["previous_close"], btc["currency"], btc["name"]),
                         (82969.0, 82912.7, "USD", "Bitcoin"))
        self.assertEqual(found["crypto:ETH-EUR"]["price"], 2233.55)

    def test_unknown_pair_splits_batch(self):
        market, transport, _ = make_market()
        transport.add(KRAKEN, "/0/public/Ticker", fixture("kraken_unknown_pair.json"), pair="ERGUSD,XBTUSD")
        transport.add(KRAKEN, "/0/public/Ticker", fixture("kraken_unknown_pair.json"), pair="ERGUSD")
        transport.add(KRAKEN, "/0/public/Ticker", fixture("kraken_ticker.json"), pair="XBTUSD")
        found, errors = market.kraken.quotes([S("crypto:BTC-USD"), S("crypto:ERG-USD")])
        self.assertIn("crypto:BTC-USD", found)
        self.assertIsInstance(errors["crypto:ERG-USD"], NotFound)

    def test_unsupported_quote_currency_skips_request(self):
        market, transport, _ = make_market()
        found, errors = market.kraken.quotes([S("crypto:BTC-MXN")])
        self.assertIsInstance(errors["crypto:BTC-MXN"], NotFound)
        self.assertEqual(transport.calls, [])

    def test_ohlc(self):
        market, transport, clock = make_market()
        clock.now = 1791720000
        transport.add(KRAKEN, "/0/public/OHLC", fixture("kraken_ohlc.json"))
        h = market.kraken.history(S("crypto:BTC-USD"), "5d", "1d")
        self.assertEqual(transport.calls[0]["query"]["interval"], "1440")
        self.assertEqual(h["currency"], "USD")
        self.assertEqual(len(h["candles"]), 5)
        self.assertEqual(h["candles"][0], {"t": "2026-10-07T00:00:00Z", "o": 85542.0, "h": 85588.9,
                                           "l": 82720.3, "c": 83276.1, "v": 3278.10115136})

    def test_ohlc_unsupported_combo(self):
        market, transport, _ = make_market()
        for rng, interval in [("max", "1d"), ("5y", "1d"), ("1y", "1mo"), ("6mo", "1h")]:
            with self.subTest(rng=rng), self.assertRaises(ProviderUnavailable):
                market.kraken.history(S("crypto:BTC-USD"), rng, interval)
        self.assertEqual(transport.calls, [])


class CoinGeckoTests(unittest.TestCase):
    def test_simple_price_batch(self):
        market, transport, _ = make_market()
        transport.add(CG, "/api/v3/simple/price", fixture("coingecko_simple_price.json"))
        found, errors = market.coingecko.quotes([S("crypto:BTC-USD"), S("crypto:ETH-EUR")])
        self.assertEqual(errors, {})
        q = transport.calls[0]["query"]
        self.assertEqual((q["ids"], q["vs_currencies"], q["include_24hr_change"]),
                         ("bitcoin,ethereum", "eur,usd", "true"))
        btc = found["crypto:BTC-USD"]
        self.assertEqual(btc["price"], 82969)
        self.assertAlmostEqual(btc["previous_close"], 82969 / 1.0024419024182637, places=4)
        self.assertEqual(btc["as_of"], "2026-10-11T11:39:49Z")

    def test_id_search_and_cache(self):
        market, transport, clock = make_market(env={"MD_COINGECKO_KEY": "CG-demo"})
        transport.add(CG, "/api/v3/search", fixture("coingecko_search_qnt.json"))
        transport.add(CG, "/api/v3/simple/price", fixture("coingecko_simple_price_qnt.json"))
        q = market.coingecko.quote(S("crypto:QNT-USD"))
        self.assertEqual((q["name"], q["price"]), ("Quant", 81.42))
        self.assertEqual(transport.calls[0]["headers"]["x-cg-demo-api-key"], "CG-demo")
        clock.advance(6 * 86400)
        market.coingecko.quote(S("crypto:QNT-USD"))
        self.assertEqual(len(transport.calls_to(CG)), 3)  # búsqueda cacheada: 1 search + 2 price
        clock.advance(2 * 86400)
        market.coingecko.quote(S("crypto:QNT-USD"))
        self.assertEqual(len([c for c in transport.calls if c["path"] == "/api/v3/search"]), 2)

    def test_search_results(self):
        from providers.coingecko import CoinGecko
        coins = load("coingecko_search_apple.json")["coins"]
        results = CoinGecko.parse_search(coins)
        self.assertEqual(results[0], {"symbol": "crypto:AAPLX-USD", "name": "Apple xStock",
                                      "type": "crypto", "exchange": None})


class BinanceTests(unittest.TestCase):
    def test_batch(self):
        market, transport, _ = make_market()
        transport.add(BINANCE, "/api/v3/ticker/24hr", fixture("binance_24hr.json"))
        found, errors = market.binance.quotes([S("crypto:BTC-USD"), S("crypto:ETH-EUR")])
        self.assertEqual(errors, {})
        self.assertEqual(transport.calls[0]["query"]["symbols"], '["BTCUSDT","ETHEUR"]')
        btc = found["crypto:BTC-USD"]
        self.assertEqual((btc["price"], btc["previous_close"], btc["currency"]), (83058.0, 82856.0, "USD"))

    def test_invalid_symbol_splits_batch(self):
        market, transport, _ = make_market()
        invalid = fixture("binance_invalid_symbol.json", 400)
        transport.add(BINANCE, "/api/v3/ticker/24hr", invalid, symbols='["BTCUSDT","ERGUSDT"]')
        transport.add(BINANCE, "/api/v3/ticker/24hr", invalid, symbol="ERGUSDT")
        transport.add(BINANCE, "/api/v3/ticker/24hr",
                      json_response(load("binance_24hr.json")[0]), symbol="BTCUSDT")
        found, errors = market.binance.quotes([S("crypto:BTC-USD"), S("crypto:ERG-USD")])
        self.assertIn("crypto:BTC-USD", found)
        self.assertIsInstance(errors["crypto:ERG-USD"], NotFound)


class EcbTests(unittest.TestCase):
    def test_parse_daily(self):
        days = ecb_mod.parse_xml(fixture_bytes("ecb_daily.xml"))
        self.assertEqual(len(days), 1)
        date, rates = days[0]
        self.assertEqual(date, "2026-10-09")
        self.assertEqual(rates["USD"], 1.1206)
        self.assertEqual(len(rates), 29)

    def test_cross_rates(self):
        rates = {"USD": 1.1206, "JPY": 177.34, "GBP": 0.84763}
        self.assertEqual(ecb_mod.cross(rates, "EUR", "USD"), 1.1206)
        self.assertAlmostEqual(ecb_mod.cross(rates, "USD", "EUR"), 1 / 1.1206, places=7)
        self.assertAlmostEqual(ecb_mod.cross(rates, "USD", "JPY"), 177.34 / 1.1206, places=4)
        self.assertAlmostEqual(ecb_mod.cross(rates, "GBP", "USD"), 1.1206 / 0.84763, places=6)
        self.assertIsNone(ecb_mod.cross(rates, "ARS", "USD"))

    def test_quote_with_previous_day(self):
        market, transport, _ = make_market()
        add_ecb(transport)
        q = market.ecb.quote(S("fx:USDJPY"))
        self.assertAlmostEqual(q["price"], 177.34 / 1.1206, places=4)
        self.assertAlmostEqual(q["previous_close"], 177.05 / 1.1186, places=4)
        self.assertEqual((q["currency"], q["as_of"], q["source"]), ("JPY", "2026-10-09T15:00:00Z", "ecb"))
        market.ecb.quote(S("fx:EURGBP"))
        self.assertEqual(len(transport.calls), 2)  # tablas cacheadas

    def test_unknown_currency(self):
        market, transport, _ = make_market()
        add_ecb(transport)
        with self.assertRaises(NotFound):
            market.ecb.quote(S("fx:EURARS"))


class KeyedTests(unittest.TestCase):
    def test_disabled_without_key(self):
        market, transport, _ = make_market()
        self.assertFalse(market.twelvedata.enabled or market.finnhub.enabled or market.alphavantage.enabled)
        self.assertNotIn("twelvedata", market.health()["providers"])

    def test_twelvedata_params(self):
        self.assertEqual(td_params(S("etf:IWDA.AS")), {"symbol": "IWDA", "mic_code": "XAMS"})
        self.assertEqual(td_params(S("stock:AAPL")), {"symbol": "AAPL"})
        self.assertEqual(td_params(S("index:^GSPC")), {"symbol": "SPX"})
        self.assertEqual(td_params(S("fx:EURUSD")), {"symbol": "EUR/USD"})
        with self.assertRaises(NotFound):
            td_params(S("bond:^TNX"))

    def test_twelvedata_quote_and_series(self):
        market, transport, clock = make_market(env={"MD_TWELVEDATA_KEY": "td_key"})
        transport.add("api.twelvedata.com", "/quote", fixture("twelvedata_quote_iwda.json"))
        transport.add("api.twelvedata.com", "/time_series", fixture("twelvedata_series_aapl.json"))
        q = market.twelvedata.quote(S("etf:IWDA.AS"))
        self.assertEqual((q["price"], q["previous_close"], q["currency"]), (111.47, 112.54, "EUR"))
        self.assertEqual(transport.calls[0]["headers"]["Authorization"], "apikey td_key")
        self.assertNotIn("td_key", transport.calls[0]["url"])
        h = market.twelvedata.history(S("stock:AAPL"), "1mo", "1d")
        self.assertEqual([c["c"] for c in h["candles"]], [252.9, 254.32001, 250.11])
        self.assertEqual(h["candles"][0]["t"], "2026-10-07T00:00:00Z")
        self.assertEqual(transport.calls[1]["query"]["interval"], "1day")

    def test_twelvedata_errors(self):
        market, transport, _ = make_market(env={"MD_TWELVEDATA_KEY": "k"})
        transport.add("api.twelvedata.com", "/quote", [fixture("twelvedata_error_not_found.json"),
                                                       fixture("twelvedata_error_limit.json")])
        with self.assertRaises(NotFound):
            market.twelvedata.quote(S("stock:ZZZZ"))
        with self.assertRaises(RateLimited):
            market.twelvedata.quote(S("stock:AAPL"))
        self.assertEqual(market.twelvedata.status(), "cooldown")

    def test_finnhub(self):
        market, transport, _ = make_market(env={"MD_FINNHUB_KEY": "fh"})
        transport.add("finnhub.io", "/api/v1/quote", fixture("finnhub_quote_aapl.json"), symbol="AAPL")
        transport.add("finnhub.io", "/api/v1/quote", fixture("finnhub_quote_unknown.json"), symbol="ZZZZ")
        q = market.finnhub.quote(S("stock:AAPL"))
        self.assertEqual((q["price"], q["previous_close"], q["currency"]), (250.11, 254.32, "USD"))
        self.assertEqual(transport.calls[0]["headers"]["X-Finnhub-Token"], "fh")
        with self.assertRaises(NotFound):
            market.finnhub.quote(S("stock:ZZZZ"))
        with self.assertRaises(NotFound):
            market.finnhub.quote(S("etf:IWDA.AS"))

    def test_alphavantage(self):
        market, transport, _ = make_market(env={"MD_ALPHAVANTAGE_KEY": "av"})
        transport.add("www.alphavantage.co", "/query", [fixture("alphavantage_quote_aapl.json"),
                                                        fixture("alphavantage_note.json")])
        q = market.alphavantage.quote(S("stock:AAPL"))
        self.assertEqual((q["price"], q["previous_close"], q["as_of"]),
                         (250.11, 254.32, "2026-10-09T00:00:00Z"))
        with self.assertRaises(RateLimited):
            market.alphavantage.quote(S("stock:AAPL"))


if __name__ == "__main__":
    unittest.main()
