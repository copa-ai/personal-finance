"""Parseo y validación estricta de símbolos canónicos."""

import unittest

import _support  # noqa: F401
import symbols


class SymbolTests(unittest.TestCase):
    def test_valid(self):
        for text in ["crypto:BTC-USD", "crypto:ETH-EUR", "crypto:USDT-EUR", "index:^GSPC",
                     "index:^IBEX", "etf:IWDA.AS", "stock:AAPL", "stock:BRK-B",
                     "commodity:GC=F", "bond:^TNX", "fx:EURUSD", "fund:0P0000XXXX.F"]:
            sym = symbols.parse(text)
            self.assertEqual(sym.canonical, text)

    def test_invalid(self):
        for text in ["", "AAPL", "stock:", "Stock:AAPL", "stock:aapl", "shares:AAPL",
                     "crypto:BTC", "crypto:BTCUSD", "crypto:btc-usd", "fx:EUR", "fx:EURUSD=X",
                     "fx:EUR/USD", "stock:AAPL\n", "stock:AA PL", "stock:../etc",
                     "index:^^GSPC", "stock:" + "A" * 30, "stock:AAPL;rm", "crypto:BTC-USD\n",
                     "stock:AAPL:X", None, 123]:
            with self.subTest(text=text):
                self.assertFalse(symbols.is_valid(text))
                with self.assertRaises(symbols.SymbolError):
                    symbols.parse(text)

    def test_parts_and_yahoo_ticker(self):
        btc = symbols.parse("crypto:BTC-USD")
        self.assertEqual((btc.base, btc.quote, btc.yahoo_ticker), ("BTC", "USD", "BTC-USD"))
        fx = symbols.parse("fx:EURUSD")
        self.assertEqual((fx.base, fx.quote, fx.yahoo_ticker), ("EUR", "USD", "EURUSD=X"))
        self.assertEqual(symbols.parse("index:^GSPC").yahoo_ticker, "^GSPC")


if __name__ == "__main__":
    unittest.main()
