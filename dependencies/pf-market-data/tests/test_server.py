"""Contrato HTTP de extremo a extremo: servidor real en un puerto aleatorio, red falsa."""

import http.client
import json
import threading
import unittest
from urllib.parse import quote

import _support
from _support import KRAKEN, add_ecb, add_yahoo, fixture, make_market, status
import server


class ServerTests(unittest.TestCase):
    def start(self, env=None):
        market, transport, clock = make_market(env=env)
        httpd = server.Server(("127.0.0.1", 0), server.Handler, market)
        thread = threading.Thread(target=httpd.serve_forever, kwargs={"poll_interval": 0.05}, daemon=True)
        thread.start()

        def stop():
            httpd.shutdown()
            httpd.server_close()
            thread.join(timeout=5)
            market.close()

        self.addCleanup(stop)
        self.port = httpd.server_address[1]
        return transport

    def call(self, method, path, body=None, headers=None):
        conn = http.client.HTTPConnection("127.0.0.1", self.port, timeout=10)
        self.addCleanup(conn.close)
        data = json.dumps(body).encode() if body is not None and not isinstance(body, bytes) else body
        conn.request(method, path, body=data, headers=headers or {})
        resp = conn.getresponse()
        raw = resp.read()
        self.assertEqual(resp.getheader("Content-Type"), "application/json; charset=utf-8")
        return resp.status, json.loads(raw.decode("utf-8"))

    def test_health(self):
        self.start()
        code, body = self.call("GET", "/health")
        self.assertEqual(code, 200)
        self.assertEqual(body, {"ok": True, "providers": {
            "yahoo": "ok", "kraken": "ok", "coingecko": "ok", "binance": "ok", "ecb": "ok"}})

    def test_quote_partial(self):
        transport = self.start()
        transport.add(KRAKEN, "/0/public/Ticker", fixture("kraken_ticker.json"))
        add_yahoo(transport, "^GSPC", fixture("yahoo_chart_quote_gspc.json"))
        add_yahoo(transport, "XXXX", fixture("yahoo_chart_not_found.json", 404))
        symbols = quote("crypto:BTC-USD,index:^GSPC,stock:XXXX", safe=",:")
        code, body = self.call("GET", f"/quote?symbols={symbols}")
        self.assertEqual(code, 200)
        self.assertEqual([q["symbol"] for q in body["quotes"]], ["crypto:BTC-USD", "index:^GSPC"])
        self.assertEqual(body["quotes"][1]["source"], "yahoo")
        self.assertEqual(body["errors"], [{"symbol": "stock:XXXX", "error": "no encontrado"}])

    def test_quote_unencoded_query(self):
        transport = self.start()
        add_yahoo(transport, "GC=F", fixture("yahoo_chart_quote_gspc.json"))
        code, body = self.call("GET", "/quote?symbols=commodity:GC=F")
        self.assertEqual((code, body["quotes"][0]["symbol"]), (200, "commodity:GC=F"))

    def test_bad_requests(self):
        self.start()
        for path in ["/quote", "/quote?symbols=AAPL", "/history?symbol=stock:AAPL&range=5y&interval=1h",
                     "/history?symbol=stock:AAPL&range=1mo&interval=2d", "/search", "/search?q="]:
            with self.subTest(path=path):
                code, body = self.call("GET", path)
                self.assertEqual(code, 400)
                self.assertIsInstance(body["error"], str)

    def test_history(self):
        transport = self.start()
        add_yahoo(transport, "^GSPC", fixture("yahoo_chart_history_gspc.json"))
        code, body = self.call("GET", "/history?symbol=index:%5EGSPC&range=5d&interval=1d")
        self.assertEqual(code, 200)
        self.assertEqual(set(body), {"symbol", "currency", "source", "candles"})
        self.assertEqual(len(body["candles"]), 5)

    def test_fx(self):
        transport = self.start()
        add_ecb(transport)
        code, body = self.call("GET", "/fx")
        self.assertEqual(code, 200)
        self.assertEqual(body["base"], "EUR")

    def test_notify_without_config(self):
        self.start()
        code, body = self.call("POST", "/notify", {"title": "t", "message": "m"},
                               {"Content-Type": "application/json"})
        self.assertEqual((code, body), (503, {"error": "ntfy no configurado"}))

    def test_notify_relay_and_bad_json(self):
        transport = self.start(env={"MD_NTFY_URL": "https://ntfy.sh/pf-test"})
        transport.add("ntfy.sh", "/", status(200, b'{"id":"1"}'))
        code, body = self.call("POST", "/notify", {"title": "Hola", "message": "Mañana", "tags": ["moneybag"]})
        self.assertEqual((code, body), (200, {"ok": True}))
        code, body = self.call("POST", "/notify", b"{not json")
        self.assertEqual(code, 400)

    def test_routing(self):
        self.start()
        self.assertEqual(self.call("GET", "/nope")[0], 404)
        self.assertEqual(self.call("GET", "/notify")[0], 405)
        self.assertEqual(self.call("POST", "/quote", b"{}")[0], 405)
        self.assertEqual(self.call("DELETE", "/health")[0], 405)

    def test_upstream_down_history_is_502(self):
        transport = self.start()
        add_yahoo(transport, "^GSPC", status(500))
        code, body = self.call("GET", "/history?symbol=index:^GSPC&range=1mo&interval=1d")
        self.assertEqual((code, body), (502, {"error": "proveedores no disponibles"}))


if __name__ == "__main__":
    unittest.main()
