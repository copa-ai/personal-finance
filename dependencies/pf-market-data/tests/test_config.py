"""Contrato de entorno y paso de resolver DNS."""

import os
import tempfile
import unittest

import _support  # noqa: F401
import config
import resolver


class ConfigTests(unittest.TestCase):
    def test_defaults(self):
        cfg = config.load({})
        self.assertEqual(cfg.port, 8080)
        self.assertEqual(cfg.quote_ttl, 120)
        self.assertEqual(cfg.cache_path, "/data/cache.sqlite")
        self.assertIsNone(cfg.ntfy_url)
        self.assertEqual(cfg.allowed_hosts, config.BASE_ALLOWED_HOSTS)

    def test_bad_values_refused(self):
        for env in [{"MD_PORT": "abc"}, {"MD_PORT": "0"}, {"MD_QUOTE_TTL": "-1"},
                    {"MD_DNS_SERVERS": "dns.google"}, {"MD_DNS_SERVERS": "1.1.1.1 2.2.2.2 3.3.3.3 4.4.4.4"},
                    {"MD_NTFY_URL": "http://ntfy.sh/topic"}, {"MD_NTFY_URL": "https://ntfy.sh/"},
                    {"MD_NTFY_URL": "https://u:p@ntfy.sh/topic"}, {"MD_NTFY_URL": "https://ntfy.sh:8443/t"},
                    {"MD_TWELVEDATA_KEY": "abc def"}]:
            with self.subTest(env=env), self.assertRaises(config.ConfigError):
                config.load(env)

    def test_ntfy_host_added_to_allowlist(self):
        cfg = config.load({"MD_NTFY_URL": "https://Ntfy.Example.org/alertas", "MD_NTFY_TOKEN": "tk_1"})
        self.assertIn("ntfy.example.org", cfg.allowed_hosts)
        self.assertEqual(cfg.ntfy_token, "tk_1")

    def test_env_names_match_service_json(self):
        import json
        with open(os.path.join(_support.ROOT, ".service", "service.json")) as f:
            declared = json.load(f)["envs"]
        self.assertEqual(sorted(declared), sorted(config.ENV_NAMES))


class ResolverTests(unittest.TestCase):
    def setUp(self):
        self.dir = tempfile.TemporaryDirectory()
        self.addCleanup(self.dir.cleanup)
        self.conf = os.path.join(self.dir.name, "resolv.conf")
        self.node = os.path.join(self.dir.name, "__config__")

    def read_conf(self):
        with open(self.conf) as f:
            return f.read()

    def run_main(self, env):
        return resolver.main(env, path=self.conf, node_config=self.node)

    def test_outside_node_keeps_resolver(self):
        self.assertEqual(self.run_main({}), 0)
        self.assertFalse(os.path.exists(self.conf))

    def test_under_node_writes_public_resolvers(self):
        with open(self.node, "w"):
            pass
        self.assertEqual(self.run_main({}), 0)
        text = self.read_conf()
        for server in config.DEFAULT_DNS_SERVERS:
            self.assertIn(f"nameserver {server}", text)

    def test_explicit_servers(self):
        self.assertEqual(self.run_main({"MD_DNS_SERVERS": "192.168.1.1, 9.9.9.9"}), 0)
        self.assertIn("nameserver 192.168.1.1", self.read_conf())

    def test_bad_servers_stop_start(self):
        self.assertEqual(self.run_main({"MD_DNS_SERVERS": "1.1.1.1\nnameserver 6.6.6.6"}), 2)


if __name__ == "__main__":
    unittest.main()
