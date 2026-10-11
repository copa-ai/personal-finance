"""Allowlist de hosts, solo HTTPS, redirecciones y limitadores."""

import unittest

import _support
from _support import FakeClock, FakeTransport, status
from errors import HostNotAllowed
from limits import ProviderState, TokenBucket
from netclient import HttpClient, check_url, redact

ALLOWED = {"api.kraken.com", "query1.finance.yahoo.com"}


class AllowlistTests(unittest.TestCase):
    def test_allowed(self):
        check_url("https://api.kraken.com/0/public/Ticker?pair=XBTUSD", ALLOWED)
        check_url("https://API.KRAKEN.COM:443/x", ALLOWED)

    def test_refused(self):
        for url in ["http://api.kraken.com/x", "https://api.kraken.com.evil.example/x",
                    "https://evil.example/?h=api.kraken.com", "https://api.kraken.com@evil.example/",
                    "https://user:pw@api.kraken.com/", "https://api.kraken.com:8443/",
                    "https://kraken.com/", "file:///etc/passwd", "ftp://api.kraken.com/",
                    "https://xn--pi-kraken.com/", "https://[::1]/", "https://api.kraken.com:99999/"]:
            with self.subTest(url=url), self.assertRaises(HostNotAllowed):
                check_url(url, ALLOWED)

    def test_client_refuses_before_transport(self):
        transport = FakeTransport()
        client = HttpClient(transport, ALLOWED)
        with self.assertRaises(HostNotAllowed):
            client.get("https://example.com/")
        self.assertEqual(transport.calls, [])

    def test_redirect_to_other_host_not_followed(self):
        transport = FakeTransport().add(
            "api.kraken.com", "/a", status(302, headers=[("Location", "https://evil.example/b")]))
        resp = HttpClient(transport, ALLOWED).get("https://api.kraken.com/a")
        self.assertEqual(resp.status, 302)
        self.assertEqual(transport.hosts(), ["api.kraken.com"])

    def test_redirect_same_host_followed(self):
        transport = (FakeTransport()
                     .add("api.kraken.com", "/a", status(301, headers=[("Location", "/b")]))
                     .add("api.kraken.com", "/b", status(200, b"{}")))
        resp = HttpClient(transport, ALLOWED).get("https://api.kraken.com/a")
        self.assertEqual(resp.status, 200)

    def test_redact_drops_query(self):
        self.assertEqual(redact("https://www.alphavantage.co/query?apikey=SECRET&x=1"),
                         "www.alphavantage.co/query")


class LimitTests(unittest.TestCase):
    def test_token_bucket(self):
        clock = FakeClock()
        bucket = TokenBucket(2, clock)
        self.assertTrue(bucket.try_acquire())
        self.assertTrue(bucket.try_acquire())
        self.assertFalse(bucket.try_acquire())
        clock.advance(30)
        self.assertTrue(bucket.try_acquire())
        self.assertFalse(bucket.try_acquire())

    def test_cooldown_exponential_backoff(self):
        clock = FakeClock()
        state = ProviderState(clock)
        self.assertEqual(state.status(), "ok")
        durations = []
        for _ in range(7):
            state.rate_limited()
            durations.append(state.cooldown_until - clock())
            clock.advance(durations[-1])
        self.assertEqual(durations, [60, 120, 240, 480, 960, 1800, 1800])
        state.rate_limited()
        self.assertEqual(state.status(), "cooldown")
        clock.advance(1800)
        state.success()
        self.assertEqual(state.status(), "ok")
        state.rate_limited()
        self.assertEqual(state.cooldown_until - clock(), 60)


if __name__ == "__main__":
    unittest.main()
