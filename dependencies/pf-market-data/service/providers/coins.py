"""Tabla integrada de las principales criptomonedas: símbolo -> (id de CoinGecko, nombre).

Evita una búsqueda en CoinGecko para los casos habituales y da nombre a las
cotizaciones de Kraken/Binance, que no lo incluyen. El resto se resuelve con
`/api/v3/search` y se cachea 7 días.
"""

COINS = {
    "BTC": ("bitcoin", "Bitcoin"),
    "ETH": ("ethereum", "Ethereum"),
    "USDT": ("tether", "Tether"),
    "XRP": ("ripple", "XRP"),
    "BNB": ("binancecoin", "BNB"),
    "SOL": ("solana", "Solana"),
    "USDC": ("usd-coin", "USDC"),
    "DOGE": ("dogecoin", "Dogecoin"),
    "TRX": ("tron", "TRON"),
    "ADA": ("cardano", "Cardano"),
    "LINK": ("chainlink", "Chainlink"),
    "AVAX": ("avalanche-2", "Avalanche"),
    "XLM": ("stellar", "Stellar"),
    "SUI": ("sui", "Sui"),
    "BCH": ("bitcoin-cash", "Bitcoin Cash"),
    "HBAR": ("hedera-hashgraph", "Hedera"),
    "LTC": ("litecoin", "Litecoin"),
    "DOT": ("polkadot", "Polkadot"),
    "TON": ("the-open-network", "Toncoin"),
    "SHIB": ("shiba-inu", "Shiba Inu"),
    "XMR": ("monero", "Monero"),
    "DAI": ("dai", "Dai"),
    "UNI": ("uniswap", "Uniswap"),
    "PEPE": ("pepe", "Pepe"),
    "AAVE": ("aave", "Aave"),
    "NEAR": ("near", "NEAR Protocol"),
    "APT": ("aptos", "Aptos"),
    "ICP": ("internet-computer", "Internet Computer"),
    "ETC": ("ethereum-classic", "Ethereum Classic"),
    "POL": ("polygon-ecosystem-token", "POL (ex-MATIC)"),
    "ATOM": ("cosmos", "Cosmos Hub"),
    "ALGO": ("algorand", "Algorand"),
    "FIL": ("filecoin", "Filecoin"),
    "ARB": ("arbitrum", "Arbitrum"),
    "OP": ("optimism", "Optimism"),
    "KAS": ("kaspa", "Kaspa"),
    "XTZ": ("tezos", "Tezos"),
    "ERG": ("ergo", "Ergo"),
}


def coin_name(base: str) -> str:
    entry = COINS.get(base)
    return entry[1] if entry else base
