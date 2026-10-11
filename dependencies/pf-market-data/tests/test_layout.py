"""El árbol de empaquetado tal y como lo lee `nodo pack`, y la promesa de solo stdlib."""

import ast
import json
import os
import re
import stat
import sys
import tempfile
import unittest
from unittest import mock

import _support
import config
from cache import Cache

SERVICE_DIR = os.path.join(_support.ROOT, ".service")


def read(name):
    with open(os.path.join(SERVICE_DIR, name), encoding="utf-8") as f:
        return f.read()


class LayoutTests(unittest.TestCase):
    def setUp(self):
        self.spec = json.loads(read("service.json"))
        self.dockerfile = read("Dockerfile")

    def test_service_json(self):
        self.assertEqual(self.spec["architecture"], "linux/amd64")
        self.assertEqual(self.spec["api"][0]["port"], config.Config().port)
        self.assertEqual(self.spec["api"][0]["protocol"], ["http1.1", "rest"])
        self.assertEqual(self.spec["network"][0]["tags"], ["*"])
        self.assertIn("MD_DNS_SERVERS", self.spec["network"][0]["prose"])
        self.assertLessEqual(self.spec["resources"]["at_most"]["mem_limit"], 256 * 1024 * 1024)

    def test_entrypoint_installed_by_dockerfile(self):
        target = "/" + "/".join(self.spec["init"]["entry_path"])
        self.assertRegex(self.dockerfile, rf"COPY start\.sh {re.escape(target)}\n")
        mode = os.stat(os.path.join(SERVICE_DIR, "start.sh")).st_mode
        self.assertTrue(mode & stat.S_IXUSR, "start.sh debe ser ejecutable")

    def test_dockerfile_rules(self):
        # nodo ignora CMD/ENTRYPOINT/EXPOSE/ENV; los COPY del proyecto llevan `./`.
        for directive in ("CMD", "ENTRYPOINT", "EXPOSE", "ENV"):
            self.assertNotRegex(self.dockerfile, rf"(?m)^{directive}\b")
        self.assertIn("COPY ./service /opt/pf-market-data", self.dockerfile)
        self.assertNotRegex(self.dockerfile, r"(?m)^[^#\n]*pip\s+install")

    def test_pack_config_includes_service(self):
        pack = json.loads(read("pack_config.json"))
        self.assertIn("service", pack["include"])
        self.assertIn(".service/pack_config.json", pack["include"])

    def test_only_standard_library(self):
        local = {os.path.splitext(n)[0] for n in os.listdir(_support.SERVICE)} | {"providers"}
        for dirpath, _, files in os.walk(_support.SERVICE):
            for name in files:
                if not name.endswith(".py"):
                    continue
                path = os.path.join(dirpath, name)
                with open(path, encoding="utf-8") as f:
                    tree = ast.parse(f.read())
                for node in ast.walk(tree):
                    if isinstance(node, ast.Import):
                        mods = [a.name for a in node.names]
                    elif isinstance(node, ast.ImportFrom) and node.level == 0:
                        mods = [node.module]
                    else:
                        continue
                    for mod in mods:
                        top = mod.split(".")[0]
                        with self.subTest(file=name, module=mod):
                            self.assertTrue(top in sys.stdlib_module_names or top in local)

    def test_cache_falls_back_when_not_writable(self):
        with tempfile.TemporaryDirectory() as tmp:
            blocker = os.path.join(tmp, "file")
            with open(blocker, "w"):
                pass
            fallback = os.path.join(tmp, "fallback", "cache.sqlite")
            with mock.patch.object(config, "fallback_cache_path", return_value=fallback):
                cache = Cache(os.path.join(blocker, "sub", "cache.sqlite"))  # el padre es un fichero
            self.addCleanup(cache.close)
            self.assertEqual(cache.path, fallback)
            cache.set("k", {"v": 1})
            self.assertEqual(cache.get("k")[0], {"v": 1})


if __name__ == "__main__":
    unittest.main()
