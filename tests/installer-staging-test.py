"""Exercise the plugin's binary install block with all paths redirected to a sandbox."""

import io
import os
from pathlib import Path
import shutil
import stat
import subprocess
import tarfile
import tempfile
import unittest
import xml.etree.ElementTree as ET


ROOT = Path(__file__).resolve().parents[1]
BINARY = b"#!/bin/sh\necho netbird-test\n"


class InstallerStagingTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix="netbird-install-test-")
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name)
        self.shared = self.root / "shared"
        self.sbin = self.root / "sbin"
        self.cache = self.root / "cache"
        self.tools = self.root / "tools"
        for directory in (self.shared, self.sbin, self.cache, self.tools):
            directory.mkdir()
        plugin = ET.parse(ROOT / "plugin/netbird.plg").getroot()
        self.install_script = plugin.find("./FILE[@Run='/bin/bash']/INLINE").text
        block = self.install_script.split("# Extract NetBird upstream binary into /usr/local/sbin\n", 1)[1]
        block = block.split("# Persist NetBird's two state dirs", 1)[0]
        # Run the actual installer code, changing only its absolute destinations.
        self.block = (block.replace("/tmp/", str(self.shared) + "/")
                      .replace("/usr/local/sbin", str(self.sbin))
                      .replace("/boot/config/plugins/netbird/", str(self.cache) + "/"))
        archive = next(node.attrib["Name"] for node in plugin.findall("FILE")
                       if node.attrib.get("Name", "").endswith("_linux_amd64.tar.gz"))
        self.archive = self.cache / Path(archive).name
        self.target = self.sbin / "netbird"
        self.target.write_bytes(b"old binary")
        self.target.chmod(0o755)
        self.env = dict(os.environ, PATH=str(self.tools) + os.pathsep + os.environ["PATH"])
        self.continued = self.root / "continued"

        # Existing shared filenames, including a planted symlink, must survive.
        self.victim = self.root / "unrelated-file"
        self.victim.write_bytes(b"keep this file")
        (self.shared / "netbird").symlink_to(self.victim)
        for name in ("LICENSE", "README.md", "changelog.yml"):
            (self.shared / name).write_text("keep " + name)
        (self.shared / "LICENSES").mkdir()
        (self.shared / "LICENSES" / "keep.txt").write_text("keep licenses")
        self.shared_names = set(self.shared.iterdir())

    def make_archive(self, binary=True):
        entries = {"LICENSE": b"archive license", "README.md": b"archive readme",
                   "LICENSES/license.txt": b"archive license", "changelog.yml": b"archive changelog"}
        if binary:
            entries["netbird"] = BINARY
        with tarfile.open(self.archive, "w:gz") as archive:
            for name, data in entries.items():
                entry = tarfile.TarInfo(name)
                entry.size = len(data)
                entry.mode = 0o755 if name == "netbird" else 0o644
                archive.addfile(entry, io.BytesIO(data))

    def mock_command(self, name, body):
        command = self.tools / name
        command.write_text("#!/bin/sh\n" + body + "\n")
        command.chmod(0o755)

    def run_install(self, success):
        script = self.block + '\nprintf continued > "$NB_TEST_CONTINUED"\n'
        result = subprocess.run(["bash", "-c", script], capture_output=True, text=True,
                                timeout=10, env=dict(self.env, NB_TEST_CONTINUED=str(self.continued)))
        self.assertEqual(result.returncode == 0, success, result.stderr)
        self.assertEqual(self.continued.exists(), success)
        self.assertEqual(self.target.read_bytes(), BINARY if success else b"old binary")
        self.assertEqual(set(self.shared.iterdir()), self.shared_names, "staging directory leaked")
        self.assertTrue((self.shared / "netbird").is_symlink())
        self.assertEqual(self.victim.read_bytes(), b"keep this file")
        for name in ("LICENSE", "README.md", "changelog.yml"):
            self.assertEqual((self.shared / name).read_text(), "keep " + name)
        self.assertEqual((self.shared / "LICENSES" / "keep.txt").read_text(), "keep licenses")

    def test_success_uses_private_directory_and_installs_only_binary(self):
        self.make_archive()
        # Inspect the real staging directory before install copies the binary.
        self.mock_command("install", 'stage=$(dirname "$3")\n'
                          '[ "$(stat -c %a "$stage")" = 700 ] || exit 1\n'
                          '[ "$(ls -A "$stage")" = netbird ] || exit 1\n'
                          'exec ' + shutil.which("install") + ' "$@"')
        self.run_install(success=True)
        self.assertEqual(stat.S_IMODE(self.target.stat().st_mode), 0o755)

    def test_invalid_archive_stops_install_and_cleans_up(self):
        self.archive.write_bytes(b"not a tar archive")
        self.run_install(success=False)

    def test_missing_binary_stops_install_and_cleans_up(self):
        self.make_archive(binary=False)
        self.run_install(success=False)

    def test_failed_install_stops_and_cleans_up(self):
        self.make_archive()
        self.mock_command("install", "exit 1")
        self.run_install(success=False)

    def test_failed_mktemp_stops_install(self):
        self.make_archive()
        self.mock_command("mktemp", "exit 1")
        self.run_install(success=False)

    def test_termination_cleans_up(self):
        self.make_archive()
        self.mock_command("install", 'kill -TERM "$PPID"\nexit 1')
        self.run_install(success=False)

    def test_install_script_syntax(self):
        subprocess.run(["bash", "-n"], input=self.install_script, text=True, check=True)


if __name__ == "__main__":
    unittest.main()
