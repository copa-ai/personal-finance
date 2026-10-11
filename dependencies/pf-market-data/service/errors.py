"""Excepciones compartidas por cliente HTTP, proveedores y orquestador."""


class ProviderError(Exception):
    """El proveedor no pudo responder; se prueba el siguiente de la cadena."""


class ProviderUnavailable(ProviderError):
    """Proveedor saltado: en cooldown, sin token local o sin clave configurada."""


class RateLimited(ProviderUnavailable):
    """El proveedor respondió 429 (o equivalente): entra en cooldown."""


class NotFound(ProviderError):
    """El proveedor funciona pero no conoce el símbolo."""


class HostNotAllowed(ProviderError):
    """URL fuera de la allowlist (host no exacto, no https, puerto o credenciales)."""
