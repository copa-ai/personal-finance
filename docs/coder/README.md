# Coder

The Coder feature lets finance start another Celaut service as a child.
That child is the Coder. It is an editor service, for example Claude Code or a VS Code server.
It changes the Laravel code while finance runs. No repack is necessary.

The Coder service itself is out of scope.
The requirements for a Coder service are in [contract.md](contract.md).
The design decisions are in [decisions.md](decisions.md).
The same feature exists in personal-nutrition. This port follows its design.

## Status of the checks

What was checked, and where:

- Docker (no node): the bash test of the watcher (`docker/pruebas/test-recarga.sh`), the PHPUnit tests (they use fake node, SSH and spec classes), and the unit test of the forwarder.
- Not checked on a node: the image build with `nodo pack`, the real share mount, a real launch, the SSH banner test, the SSH login with `StrictModes`, the funding calls, the upload limits through the node, and the real disk size.
- The lead runs the node tests. Until then, do not read this file as a node result.

## Trust: what the Coder can read

The Coder is **not** a separate trust domain from the app. It edits PHP code that runs as the user `app`,
with the full environment of the app. After a reload, that code can do all that the app can do:

- Read all secrets in the environment and files of the app: `APP_KEY`, the database password, `CHAT_SECRETO`, `AGENTE_SECRETO` and the `FINANCE_RED` value in `/__config__`.
- Read the Claude token. The app stores it encrypted with `APP_KEY` (`storage/app/private/claude-token.enc`), and the app code can decrypt it.
- Read the settings of the OCR provider (`ocr_providers`: package path, address and state) and the uploaded OCR package.
- Read and change all data of the database: expenses, banks, assets, crypto, investments, comments and the audit log.
- Spend the balance of finance (the funding limits stop only an honest use).
- Run `celaut` commands as the app user.

Only the scripts that root runs, and the users `agente`, `chat` and `sshproxy`, are outside its reach.
Launch only a Coder that you trust fully. The panel shows this warning before the launch.

## The three copies of the code

The code exists in three places. Do not confuse them.

| Copy | Path | Purpose |
|------|------|---------|
| Original in the image | /opt/finance/project | The node reads it to seed the share. The share hides it at run time. |
| Live tree | /opt/finance/project (the share) | The Coder edits it. Finance runs the app from it. Laravel is at its root. |
| Project tar | /opt/finance/project.orig.tar.gz | The pristine copy. The restore action reads it. |

The share holds the whole repository: `app/`, `config/`, `database/`, `routes/`, `resources/`, `public/`, `tests/`, `vendor/`, `agente/`, `docker/`, `.service/`, `dependencies/`, `docs/` and `services/`.
The node makes the share from the image of finance when finance starts.
The tar is outside the share, so the mount does not hide it.

Runtime data is not in the share. It stays in /var/lib/finance (storage, caches, secrets, the control files).
The scripts that root runs (start.sh, the watcher, the forwarder, bucle.sh), the Caddyfile, the status page, the agent kit and the chat translator are also outside the share.
A Coder cannot change them. A change to these files needs a repack.

## How reload works

Finance watches the share by itself. The Coder sends no request.
The script docker/servicio/recarga.sh runs as a loop and polls the share every 5 seconds.

It watches these paths: `app`, `config`, `routes`, `resources`, `database`, `lang`, `bootstrap/app.php`, `bootstrap/providers.php` and `composer.lock`.
A path that does not exist is skipped. Finance has no `lang` folder today.
The setting `RECARGA_SUBDIR` (`APP_SUBDIR` in the script) holds the folder of the Laravel app inside the project. It is empty for finance.

When a change appears, the watcher does these steps:

1. It waits a short time for more changes (debounce).
2. It checks the reload lock (see below). While a Coder launch runs, it waits.
3. It runs `php -l` on the PHP files whose content changed since the last good reload.
4. It checks that the app boots (`php artisan route:list`).
5. It runs `optimize:clear`.
6. It restarts FrankenPHP, the three queue workers and the scheduler.
7. It checks that the app answers HTTP 200 on `http://127.0.0.1:8080/up`.

If a check fails, the watcher does not reload. The old code stays in memory.
The error goes to the log and to the panel.

The watcher restarts a piece with a TERM signal to its PID. The loop script `docker/servicio/bucle.sh` then starts a new process.
There are five pieces, each with a PID file in /var/lib/finance/coder:

| PID file | Command |
|----------|---------|
| web.pid | `frankenphp run` (port 8080 and the internal chat site on 127.0.0.1:8001) |
| cola-default.pid | `queue:work --queue=default` |
| cola-ocr.pid | `queue:work --queue=ocr` |
| cola-chat.pid | `queue:work --queue=chat` |
| tareas.pid | `schedule:work` |

The shared database watcher (`finance:vigilar-base`) is not in this list. It runs `php artisan` for each pass, so it loads the new code at its next pass.

After each good reload, the watcher saves a snapshot of the code directories (no `vendor`).
If the health check fails after a reload, the watcher restores the last snapshot and reports it.

A queue job that runs keeps the old code until it ends. An OCR job can run for up to 20 minutes.

### The reload lock

A reload kills the queue workers. A Coder launch runs in a queue job, so a reload would kill the launch.
Then the state would stay "launching".

To prevent this, the app writes the file `/var/lib/finance/coder/lanzando`.
The file holds the unix time in seconds. It has mode 0644, and the app writes it by rename.

- The app writes the file when the launch sets the state to "launching".
- The app removes the file when the launch ends (success, failure or exception), when the Coder stops (also a forced stop), and when `finance:vigilar-coder` marks a stuck launch as error.
- The watcher starts no reload while the file exists and is younger than 2400 seconds (`coder.lanzamiento.colgado_s`). This holds for the automatic reload, the flag `recargar-ahora` and the restore.
- The change stays pending. The flags stay in place. The watcher writes one log line each time that it starts to wait.
- When the file is gone or old, the watcher reloads.
- A file with a time in the future counts as old. A symlink named `lanzando` is not a lock.
- `recarga.json` has the field `espera_lanzamiento`. The panel then says that the reload waits for the launch.

start.sh removes an old lock at each start.

### Options and files

The panel and the watcher talk through small files in /var/lib/finance/coder.

| File | Writer | Meaning |
|------|--------|---------|
| auto-recarga | panel | Auto reload. A missing file means ON. |
| recargar-ahora | panel | Flag. Start one reload now. |
| restaurar-original | panel | Flag. Restore the original, then reload. |
| recarga.json | watcher | Status for the panel. |
| lanzando | app | Reload lock. Unix time of the start of a launch. |
| destino.json | app | Target of the SSH forwarder. |
| *.pid | bucle.sh | PID of each piece. |

Auto reload is ON by default.
When it is OFF, the watcher ignores changes until the user presses "Recargar ahora".

## Panel page

The page is "Coder" in the group Ajustes (route `/coder`). The UI text is Spanish.
It has these parts:

- A field for a service hash or an upload of a `.celaut.bee` file.
- The estimated cost and a confirm step before the launch.
- The state: none, launching, running or error.
- The balance and the hours left of the Coder and of finance.
- The buttons Add balance, "Parar el Coder", "Forzar la parada" (in the states launching and error), Reload now and Restore original.
- The switch Auto reload.
- The three funding limits.
- A text area for SSH public keys.
- The state of the live code against the original.
- The connect block and the last reload result.

## How to connect

The end user reaches the Coder through finance on port 2222.
A small forwarder (docker/servicio/ssh-proxy.py) pipes the bytes to the Coder.
Finance never reads the SSH bytes.

1. Add your public key in the panel page "Coder". Save.
2. Launch the Coder in the panel.
3. Open a tunnel on your computer:

```
nodo tunnel <finance instance> 2222 --listen 2222
```

4. Connect:

```
ssh -p 2222 -o HostKeyAlias=finance-coder coder@127.0.0.1
```

For VS Code, use the extension Remote-SSH.
Add this block to `~/.ssh/config`:

```
Host finance-coder
    HostName 127.0.0.1
    Port 2222
    User coder
    IdentityFile ~/.ssh/id_ed25519
    HostKeyAlias finance-coder
```

Then open the host `finance-coder` in VS Code.
Each tunnel connection costs MU, and finance pays it. VS Code opens several connections.
The web tunnel to port 8080 uses a different local port. Use `--listen` to keep them apart.

The forwarder closes a connection at once if there is no target.
When the Coder stops, finance removes the target file and closes the open pipes.

## How to restore

Press "Restaurar original" in the panel. The panel creates a flag file.
The watcher extracts the project tar over the share, removes the extra files in the watched paths and in `vendor`, then reloads.
Files in `docs`, `agente` and `docker` are replaced, but extra files there stay.
The restore waits while a Coder launch runs (reload lock).

## How funding works

Finance pays for the Coder. The panel does not send MU without checks.
Before each transfer, the code applies three controls in this order:

1. Total cap. The MU sent during this life plus the current balance must not pass the cap.
2. Percentage limit. After the transfer, the Coder balance must not pass a percentage of the joint balance. The default is 20 percent.
3. Minimum life of finance. After the transfer, finance must keep at least X hours of life. The default is 48 hours.

The default cap is 24 hours of Coder maintenance at the current price, with a margin of 25 percent.
The user can raise the cap, or press "Add balance" with an amount.

The life of finance is its balance divided by its burn rate.
The code measures the burn rate from samples of the balance.
If the rate is unknown, nothing is sent. Four samples over 600 seconds are the minimum.

Finance also tops up the database child (`BaseCompartida\Recarga`). The deposit counter covers both, so a database top-up never counts as burn.
The database top-up keeps its own limit of 30 percent of the joint balance.

Every refusal gets an audit row with the reason. The panel shows the last refusal.
The command `finance:vigilar-coder --saldo` runs every 300 seconds.
It tops up the Coder to a target of 12 hours when the balance is under half of that target.
It sends only the amount that the three controls allow.

## Data in the database

- The Coder settings (`coder.estado`, `coder.lease`, `coder.claves_ssh`, the three limits and others) are rows of the table `ajustes`. This table has an audit trigger, so each change has an audit row.
- Values that change often (balance samples, counters, price, last refusal) are rows of the table `coder_estado`. This table has no audit trigger on purpose.
- `coder_estado` is not in the core schema (`database/esquema-nucleo.json`), so it is not in `database/esquema.json` or in the network spec of service.json.
- The backup (`CopiaBase::OMITIR`) skips `coder_estado`. The list of the merge (`fusion_tablas()`) does not hold it, so the data merge skips it too.
- `TablasAuditadasTest` lists `coder_estado` as a table with no trigger, with the reason.

## Limits

- One Coder at a time for each shared database.
- The Coder is always a direct child of finance on the same node.
- With the default setting, a Coder that the user names by hash is refused: the node CLI cannot read the spec of a service. An uploaded `.celaut.bee` package is read, and it launches if it declares the guest share `project`. See decisions 28, 69 and 88.
- The upload limit for a package is 512 MB. Finance already accepts large OCR packages, so the PHP and Caddy limits are higher (1024 MB and 1100 MB).
- The forwarder allows 16 open pipes, and it closes a pipe after 4 hours with no traffic.
- The disk of finance is 6 GiB. The value is an estimate. See decision 7. Measure it after the first pack.
- The reload does not check the logic of the code. It checks syntax and boot only.
- A reload does not stop the app from other changes: a bad change to a Filament page can break the panel. The rollback restores the last good snapshot.

## Balance use

Measured on a node during the tests of v0.3.0. The values are approximate.

- The finance service uses about 200 000 MU each minute.
- A small Coder uses about 65 000 MU each minute.
- An instance with no balance stops. The node cannot start it again (error `no launch spec`). Launch the instance again.

Use these values to set the three limits (cap, percentage, minimum hours). The rate that the panel shows comes from real samples (decisions 46 and 48).

## What is lost when finance stops

The share is not durable. The node owns it through the finance instance.

- When finance stops, all edits in the share are lost.
- The Coder keeps running with a mount that fails. A new Coder cannot mount the old share.
- `start.sh` runs `finance:apagar-nodo` (`celaut shutdown`) when it receives TERM. This command asks the node to stop every instance that finance started. The Coder is one of them, so a clean stop of finance should stop the Coder. Nobody checked this on a node. If the VM ends with no TERM (a crash or a kill), the command does not run and the Coder stays as an orphan until its MU end. See decision 81 and TODO.md.
- When finance starts again, the share is new. It holds the code of the image.

Keep the work in git in the Coder VM. Push it to a remote often.
The Coder must stop before finance stops. Use the "Parar el Coder" button.
