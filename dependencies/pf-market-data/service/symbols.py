"""Símbolos canónicos `<kind>:<code>` y su traducción a cada proveedor.

Validación estricta: el código va en mayúsculas exactamente como se devolverá, de
modo que el cliente puede casar la respuesta con lo que pidió.
"""

import re
from dataclasses import dataclass

KINDS = ("crypto", "index", "etf", "stock", "fx", "commodity", "bond", "fund")
TRADITIONAL = frozenset({"index", "etf", "stock", "commodity", "bond", "fund"})

# Se usan con fullmatch y no ^...$: `$` aceptaría un salto de línea final.
_CRYPTO_RE = re.compile(r"[A-Z0-9]{2,10}-[A-Z]{3,4}")
_FX_RE = re.compile(r"[A-Z]{6}")
# Ticker estilo Yahoo: ^GSPC, IWDA.AS, GC=F, BRK-B, 0P0000XXXX.F
_TICKER_RE = re.compile(r"\^?[A-Z0-9][A-Z0-9.\-=]{0,23}")


class SymbolError(ValueError):
    pass


@dataclass(frozen=True)
class Symbol:
    kind: str
    code: str

    @property
    def canonical(self) -> str:
        return f"{self.kind}:{self.code}"

    # Cripto: BASE-QUOTE.
    @property
    def base(self) -> str:
        return self.code.split("-")[0] if self.kind == "crypto" else self.code[:3]

    @property
    def quote(self) -> str:
        return self.code.split("-")[1] if self.kind == "crypto" else self.code[3:]

    @property
    def yahoo_ticker(self) -> str:
        if self.kind == "fx":
            return f"{self.code}=X"
        return self.code  # cripto BTC-USD y tickers tradicionales ya son de Yahoo


def parse(text: str) -> Symbol:
    if not isinstance(text, str) or len(text) > 40 or ":" not in text:
        raise SymbolError(f"símbolo no válido: {str(text)[:40]!r}")
    kind, code = text.split(":", 1)
    if kind not in KINDS:
        raise SymbolError(f"tipo de símbolo no válido: {kind[:20]!r}")
    if kind == "crypto":
        ok = _CRYPTO_RE.fullmatch(code)
    elif kind == "fx":
        ok = _FX_RE.fullmatch(code)
    else:
        ok = _TICKER_RE.fullmatch(code)
    if not ok:
        raise SymbolError(f"símbolo no válido: {text!r}")
    return Symbol(kind, code)


def is_valid(text: str) -> bool:
    try:
        parse(text)
        return True
    except SymbolError:
        return False
