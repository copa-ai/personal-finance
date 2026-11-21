# Contract for a Coder service

This file lists the requirements for an author of a Coder service.
Finance launches any service hash that the user gives, or a package that the user uploads.
It cannot read the spec of a service by hash. For an uploaded package, it scans for the share policy (decision 88).
A service that does not follow this contract starts, but the user cannot use it.

Words in this file:

- Finance is the parent service.
- The Coder is the child service.
- The share is the directory that finance exports.

## Requirements

1. The Coder is a direct child of finance. The panel of finance launches it. Nobody else can launch it.
2. The Coder declares a shared filesystem with role `guest` and tag `project`. The tag identifies the share. The path is free. Example:

   ```json
   {"path": "/work", "role": "guest", "tag": "project", "access": "rw"}
   ```

3. The Coder runs an SSH server. The slot of the SSH server is the first api slot in `service.json`. Finance uses the address of the first slot. It cannot choose a slot by tag.
4. The SSH slot has the tag `ssh` in its protocol stack. Example:

   ```json
   {"port": 22, "transport": "tcp", "protocol_stack": [["ssh"]]}
   ```

5. The SSH server accepts the user `coder`. Other users are not necessary.
6. The SSH server accepts keys only. Turn off password authentication and keyboard-interactive authentication.
7. The SSH server sets `AllowTcpForwarding yes`. VS Code Remote-SSH needs it.
8. The SSH server reads the authorized keys from the file `.coder/authorized_keys` at the root of the share. Use `AuthorizedKeysFile` with the full path of the mount. Read the file at each login. The user changes keys in the panel, and the Coder does not restart. The app user owns the file `.coder/authorized_keys` and the directory `.coder`, and nobody else can write them. If `StrictModes yes` is on, `sshd` refuses a file with a wrong owner or a mode that other users can write. Set `StrictModes no`, or make sure that the owner check passes for the user `coder` (the file is owned by a user that is not root, so the default check fails). Test a login before you rely on it.
9. The Coder reads the host key from the environment variable `CODER_SSH_HOST_KEY`. The value is the base64 text of an OpenSSH private key (ed25519). Decode it at start. Write it to a file with mode 600. Give it to `sshd` as `HostKey`. Do not make a new host key. Finance makes the key once and keeps it, so the host key does not change between launches.
10. The Coder keeps its own work in git. The share is lost when finance stops. Push to a remote often.
11. The Coder does not share a network filesystem with finance. The share is the only link. Finance does not send a request to the Coder.
12. The Coder runs tests, composer and all other tools in its own VM. Finance runs none of them.
13. The author packs the Coder with `celaut pack`. The Coder has its own repository and its own image.
14. The Coder declares resources inside the bound that the panel shows: 3 GiB memory, 10 GiB disk and 2 CPU (config `coder.recursos`). A node can refuse a larger Coder.
15. The Coder must stay inside the cost limits. Finance pays the balance. The panel shows the cost before the launch.

## What the Coder can change

The Coder edits the files in the share. Finance reloads the app when these paths change:

- `app`, `config`, `routes`, `resources`, `database` and `lang` (a path that does not exist is skipped)
- `bootstrap/app.php` and `bootstrap/providers.php`
- `composer.lock`

Laravel is at the root of the share. There is no `admin/` folder.

Finance checks the syntax and the boot before it reloads.
If the code fails a check, finance keeps the old code and shows the error in the panel.
If the app is not healthy after the reload, finance restores the last good snapshot.

The reload restarts the web server, the three queue workers (`default`, `ocr` and `chat`) and the scheduler.
A queue job that runs keeps the old code until it ends.
While a Coder launch runs, finance does not reload (reload lock, decision 90).

A change to `docker/` or `.service/` does not change the running service.
Those files need a repack.
A change to `public/` does not start a reload.

Finance does not run `composer`. If the Coder changes `composer.lock`, it must also update `vendor` in the share.

## What finance protects

- The Coder runs as root in its own VM. It can write any file in the share, also a setuid file and a symlink. Finance clears setuid bits and removes symlinks before it extracts a tar (decision 87).
- The share root can become mode 700 and owned by root when the first child starts. The watcher opens it again (decision 89).
- The Coder can break the app with bad code. It can also read all secrets of the app (decision 86). The contract does not limit this. The user must trust the Coder.

## What finance checks at launch

- The node must hold the service, or the user must upload a `.celaut.bee` file.
- For an upload, the package must declare a guest share with the tag `project`. For a hash, the setting `coder.spec_desconocida` must be "confirmar" (default "rechazar").
- Finance connects to the address of the first slot. It reads the banner `SSH-2.0-` for up to 180 seconds.
- If there is no banner, finance stops the Coder and shows an error.
- Finance writes the target of the forwarder only after the banner test passes.

## Access for the end user

The end user opens a tunnel to port 2222 of finance. Then the user connects as `coder`.
See [README.md](README.md) for the commands.
