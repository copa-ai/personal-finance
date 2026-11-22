# Coder decision log

This file records the design decisions for the Coder feature of personal finance.
The feature came from personal-nutrition. The numbering of the entries 1 to 89 is the same as in the nutrition log.
Each entry has a Context, a Decision, a Reason and an Effect.
Some entries also have a line "Rejected options".
Entries 1 to 69 come from the build steps (image, reload, ssh, coder, funds, panel).
Entries 70 to 89 record the agreed design decisions and the limits that the build did not record.
Entries 90 to 92 are new for finance.

Where finance differs from nutrition, the entry says so.
The main differences are: Laravel is at the repository root (no `admin/` folder), the web port is 8080,
finance has three queue workers and a scheduler, and finance publishes a database schema on the network.

What the checks cover: the entries say "tested" only for Docker tests (bash, PHPUnit with fakes, Python unit test).
No entry here records a result from a real node. The lead runs the node tests later.

## 1. Project tree path and content
Context: The agreed share path is /opt/finance/project. The old code path was /opt/finance/app, built from a subset of folders.
Decision: The image copies the whole repository into /opt/finance/project: app, bootstrap, config, database, public, resources, routes, tests, artisan, composer files, phpunit.xml, package files, vite.config.js, AGENT.md, README.md, vendor, agente, docker, .service, dependencies, docs and services. It has no .git and no node_modules. Laravel is at the root of the project.
Reason: The Coder must see the whole repository. A Coder with only the app folders could not change the service files.
Effect: The share is bigger. The pack has more files (see entry 3). Finance has no `admin/` prefix anywhere. The watcher has a setting for a subfolder (`RECARGA_SUBDIR`, empty for finance), so the same script serves both projects.

## 2. Run scripts stay outside the share
Context: The Coder can write all files in the share. start.sh runs as root. The agent kit and the chat translator run as other users.
Decision: start.sh, Caddyfile, bucle.sh, recarga.sh, ssh-proxy.py, the status page, /opt/agente-kit, /opt/runner and /opt/chat stay outside the share (under /opt/finance and /opt). The Dockerfile copies them a second time. The Coder only reads and edits the project copy of docker/. The variable KIT_ADMIN in start.sh points to the project, because the agent kit reads files from the Laravel tree.
Reason: A Coder could change a script that root runs. It would gain root in the finance VM. This rule protects the root scripts and the other users (agente, chat, sshproxy). It does not make the Coder a separate trust domain from the app: the Coder can read all secrets of the app (entry 86).
Effect: A change to start.sh in the share has no effect until the next repack. The Laravel code reloads without a repack. Rejected option: run start.sh from the share.

## 3. Pack context
Context: The packer builds the image from the include list of pack_config.json. It had an explicit list and it ignored tests/.
Decision: The include list has all folders and files that the Dockerfile copies, including tests, docs and services. It also has the four files of .service (Dockerfile, service.json, pack_config.json, .dockerignore). It must not list the directory .service itself: the packer builds in .service/service, so the packer would copy that directory into itself without end and fail. The ignore list loses "tests/". It keeps vendor/, because the vendor stage builds vendor. The file .service/.dockerignore stays.
Reason: A COPY of a path that is not in the context fails. The old ignore rule removed tests, but the share must hold them.
Effect: The pack includes docker/pruebas and docker/Dockerfile.agente if it exists. It also includes the dependencies sources (pf-postgres). A new top-level folder needs a new line in the include list and in the Dockerfile.

## 4. Original copy as a tar outside the share
Context: A mount hides the image content at its path.
Decision: The Dockerfile makes /opt/finance/project.orig.tar.gz as its last step. It runs chmod -R a+rX first. The tar root is the project directory contents (tar -C project .).
Reason: The tar is the only copy of the original that stays visible after the mount. A tar of the final tree keeps the vendor and the built Filament assets.
Effect: The restore action must extract with -C /opt/finance/project. The image holds the tree twice (tree and tar). Disk size covers this (entry 7).

## 5. Writable data stays outside the share
Context: Laravel writes to storage and to bootstrap/cache.
Decision: start.sh sets LARAVEL_STORAGE_PATH and the APP_*_CACHE paths to /var/lib/finance/admin. The Dockerfile builds the Filament assets in the image (public/). The app user reads the share. It writes only one path in it: .coder/authorized_keys (entry 34).
Reason: I did not verify the share ownership on virtiofs. The Coder may create root-owned files. A read-only need is safe in both cases.
Effect: start.sh runs chmod -R a+rX on the share at each start. If chmod fails, it logs a warning and continues. A missing artisan file stops the start with a clear error on the status page. The data directory keeps the name /var/lib/finance/admin, as before.

## 6. Restartable loop script
Context: The web server, the queue workers and the scheduler must restart after a kill. Finance had inline `while true` loops for the queues and no PID files.
Decision: A new script, docker/servicio/bucle.sh (a copy of the nutrition script), runs one command in a loop. It writes the PID of the child (not of the loop) to a file. It waits one second between restarts. On TERM it kills the child, removes the file and exits. start.sh uses it for five pieces. The PID files are web.pid, cola-default.pid, cola-ocr.pid, cola-chat.pid and tareas.pid (schedule:work) in /var/lib/finance/coder.
Reason: The watcher needs the PID of the real process. A POSIX sh script needs no extra tool. The TERM handler prevents orphan workers when the service stops.
Effect: A crash loop of the web server no longer ends the service (before, wait -n ended it). The status page and the log still show the restarts. start.sh deletes old PID files at start. The shared database watcher (`finance:vigilar-base`) stays an inline loop: it starts a new PHP process at each pass, so it needs no PID. Tested with a sleep command in the bash test: kill, restart and stop work.

## 7. Disk size 6 GiB
Context: The node counts the exporter disk as image plus shared directories. It checks the value on every tick. The old value was 5 GiB.
Decision: at_init and at_most disk_space are 6 GiB (6442450944 bytes).
Reason: Estimate, not a measure. The old 5 GiB covered the image (PHP, Node, Claude Code, vendor: about 1.5 GiB), the OCR package that the user uploads (hundreds of MB) and the copies. The project tree is about 9 MB of source plus about 130 MB of vendor on the development machine. The image now holds the tree twice (tree and tar, the tar is compressed). The materialized share holds a third copy. That adds about 0.3 to 0.4 GiB. I added the rest of 1 GiB as a margin for packages that the Coder installs and for files that it creates.
Effect: The maintenance price rises a little, because the price follows resources. The value is a guess: measure the real size after the first pack and adjust. The node limit MAX_DISK_SHARE (0.5) also applies. Memory stays 1.5 GiB: the Coder runs in its own VM, and the reload adds only a short PHP process for the checks.

## 8. Slot for the Coder admission check
Context: A node must hold room for a Coder child. The agreed bound is 3 GiB memory, 10 GiB disk and 2 CPU.
Decision: The bound is in config coder.recursos. The panel shows it as "up to" at the confirm step. The nutrition service.json declares it with possible_environment_workload (one clause, one workload, only at_most), separate from the postgres clause in initial_environment_workload. When this log was written, the finance service.json did not hold that clause yet.
Reason: The check is an admission check at launch of finance. It has no cost and no reservation.
Effect: Without the clause, the node does not check the bound. The panel text is then information only. The clause is not needed for a cold launch: finance has no clause and its cold launches ended in 75 and 78 seconds. Nutrition has the clause and a cold launch can hang there, but the tests do not link the hang to the clause (nutrition decisions, entry 91).

## 9. SSH slot position
Context: The SSH slot is the fourth api slot, port 2222, tcp, protocol_stack [["ssh"]].
Decision: I added it after the cards slot (the api list is: web 8080, chat 50051, cards 50052, ssh 2222). The slot has no prose.
Reason: The order of the existing slots stays the same. The first slot (HTTP, 8080) remains the first address of finance.
Effect: Port 2222 has the forwarder (ssh-proxy.py) as listener. The ports 9000 (agent) and 8001 (internal chat site) are still not declared, so no tunnel reaches them.

## 10. Watcher is one bash script with a sourceable core
Context: The reload logic needs small functions and a local test.
Decision: docker/servicio/recarga.sh holds all logic in functions. It runs the loop only when a user runs the file as a script. The RECARGA_* variables change paths and commands. docker/pruebas/test-recarga.sh sources it with fake php, fake health and web processes that bucle.sh makes.
Reason: Tests run the real code and need no root. Bash is already in the image. A Python watcher would add a second language for the same job.
Effect: The bash test covers good reload, syntax error, boot error, health rollback, auto off, reload now, restore original, the reload lock and the subfolder setting. It does not run real PHP, FrankenPHP or setpriv.

## 11. Watcher lives outside the share, in a loop that restarts it
Context: start.sh stops the whole service when one piece ends (wait -n). The watcher runs as root and reads files the Coder writes.
Decision: The Dockerfile copies recarga.sh to /opt/finance/recarga.sh, outside the share (same rule as entry 2). start.sh starts it in a small bash loop that restarts it after 5 seconds and stops it on TERM. It passes the app environment variable names in RECARGA_NOMBRES and the values in its own environment.
Reason: The Coder must not change a root script. A watcher crash must not stop the app. The watcher needs the app environment only for php and artisan.
Effect: The watcher process holds the database password in its environment, as root. Rejected option: bucle.sh for the watcher (it writes a PID file as root in a directory that the app user owns).

## 12. Root-only state directory
Context: /var/lib/finance/coder belongs to the app user. A compromised app could plant a symlink there, and root would follow it when it writes.
Decision: The snapshot, its stamp and the manifests live in /var/lib/finance/coder-root (root, mode 700). Only the flags, the PID files, the lock and recarga.json stay in the coder directory. The watcher writes recarga.json through the app user (tee and mv with setpriv).
Reason: Root never writes to a path that the app user controls.
Effect: The panel does not need the snapshot. Root still reads and removes the flag files. A removal of a symlink removes only the link. Root also reads the file `lanzando` with care (entry 90).

## 13. File names and status fields
Context: The panel step must read and write the control files.
Decision: Flags are auto-recarga (content 0, off, no or false means OFF; a missing file means ON), recargar-ahora and restaurar-original. The status file is recarga.json. Its fields are in the header of recarga.sh: estado, resultado, mensaje, fecha, fecha_unix, auto, pendiente, cambios, arbol_vivo, arbol_original, igual_original, ultima_buena, recargas and espera_lanzamiento (new, entry 90).
Reason: Spanish names follow the rest of the panel. A flag file is simple for PHP to create and for bash to test.
Effect: The panel writes auto-recarga as the app user. The watcher removes the two action flags when it takes them. The panel must not rely on a reply before the next poll (5 seconds plus the reload time).

## 14. Cheap fingerprint, content hashes only on change
Context: The poll runs every 5 seconds. A content hash of vendor would be slow.
Decision: The poll hashes path, size and mtime (find -printf, sha256sum) of the watched paths. The watcher makes content manifests (sha256 of each file) only after a stable change and after a reload. It makes the original manifest from the tar and caches it in the root-only directory.
Reason: Polling costs little. The panel still gets exact "changed or not" data and a changed file count.
Effect: A touch without a content change starts one reload. The count of changed files is exact. Watched paths do not include vendor, public or the other repository folders. A watched path that does not exist (lang) is skipped.

## 15. Boot check is artisan route:list
Context: A syntax check does not find a broken provider, a bad route file or a missing class.
Decision: The boot check runs "php artisan route:list --json --no-interaction" as the app user. It boots the app, runs every provider and loads every route file. It does not touch the database.
Reason: "artisan --version" does not boot the providers, so it finds too little. A command that reads data would fail when the database is down and would block a good reload. start.sh never runs route:cache, so the command reads the real route files.
Effect: A run takes about one to two seconds of PHP. I did not verify this on a real PHP image: a provider that queries the database at boot would fail this check. Finance has Filament panel providers, so check this on the node.

## 16. php -l only on files that changed since the last good reload
Context: Checking every PHP file on each change is slow on virtiofs.
Decision: The file ultima-buena.manifest holds the hashes of the watched files at the last good reload. php -l runs on the watched .php files whose hash differs from it. A missing manifest means all files. A failed check leaves the manifest unchanged, so the watcher checks the files again next time. The watcher does not check Blade and JSON files. The watcher takes the fingerprint again after the checks. If the code changed meanwhile, it checks again (3 tries). The stamp file ultima-buena.stamp keeps the time of the last good reload for the status.
Reason: A time test fails when tar, cp -p or rsync -t sets an old time on a file. A hash test does not.
Effect: A restore of the original removes the stamp, so the watcher checks every file once.

## 17. Reload method and health check
Context: The loops from entry 6 restart a child that ends.
Decision: The watcher runs optimize:clear, sends TERM to the FrankenPHP PID and waits until the old process is gone and a new PID file appears (max 40 s). If this fails, the reload is an error and the old code can still run. It sends TERM to the PIDs of cola-default, cola-ocr, cola-chat and tareas, and it does not wait. A worker ends its current job first. Then bucle.sh starts it with the new code. The watcher sends TERM to a PID only if the process belongs to the app user and its command line holds frankenphp (web), queue:work (queues) or schedule:work (tareas), because the app user writes the PID files. The health check is an HTTP 200 on http://127.0.0.1:8080/up, one try per second, 30 tries.
Reason: A long job can last minutes (OCR up to 20 minutes, a chat answer without a timeout). A wait for the worker would block the reload. The route /up exists in bootstrap/app.php and needs no login.
Effect: A queue job that is running keeps the old code until it ends. optimize:clear also clears the database cache store (cache:clear): a failure only gives a log warning. During the web restart the app is down for a few seconds. The scheduler restarts with the new code: a scheduled task that runs at that moment ends first.

## 18. Rollback and error states
Context: A change can pass the checks and still break the app.
Decision: After a failed health check, the watcher restores the last good snapshot, runs optimize:clear, restarts web and queue, and checks health again. Result revertido on success. Result error_salud when there is no snapshot or the restored code also fails. After a failed check or a rollback, the watcher stores the fingerprint as seen, so it does not retry the same code every 5 seconds. A new edit starts a new attempt. At start, if the app is healthy and there is no snapshot, the current code becomes the first snapshot.
Reason: This prevents a loop of reload and rollback. The first snapshot gives the first change a safe base.
Effect: After an error the old code runs and the status shows the message until the next change or a manual reload.

## 19. Restore original details
Context: The tar holds the whole project, including vendor. The Coder can add files and packages.
Decision: The watcher extracts the whole tar over the share with --no-same-owner and without .coder. Then it removes files that the tar does not hold in the watched paths and in vendor. It then runs a normal reload with no snapshot rollback. A failed health check gives error_salud. Rejected option: delete and rebuild vendor (large and slow with the app running).
Reason: Extraction replaces changed vendor files. The prune step removes added vendor files. composer.lock and vendor match again.
Effect: The extract replaces files outside these paths (docs, agente, docker), but extra files there stay. Empty directories stay. Root extracts files that the Coder can influence. Before each extract, the watcher removes symlinks in the share (not in .coder), and it extracts with --no-same-owner. After the extract it clears setuid and setgid bits. The watcher does not handle file names with a newline. A restore waits for a running launch (entry 90).

## 20. Forwarder is a Python asyncio script with no dependency
Context: The SSH slot needs a byte pipe from port 2222 to the Coder. The image has python3, so the forwarder needs no extra package.
Decision: docker/servicio/ssh-proxy.py uses only the standard library. Settings come from arguments, then SSH_PROXY_* variables, then defaults. Rejected options: socat (not in the image, no live check of the destination file) and a PHP pipe (heavy, runs as the app user).
Reason: The script is short, testable with unittest and runs with the empty environment.
Effect: The unit tests in docker/pruebas/test_ssh_proxy.py run without a node. The default destination path is /var/lib/finance/coder/destino.json.

## 21. The destination file is read for each connection and each second
Context: The Coder can stop, or a new Coder can start, while pipes are open.
Decision: The forwarder reads destino.json for each new connection. A watcher task reads it every second. A pipe whose target is not the current target closes. A removed or invalid file closes all pipes. The same content again keeps the pipes.
Reason: This needs no signal from the panel or from vigilar-coder. The command only writes or removes the file.
Effect: A pipe closes within about one second of a change. A pipe to a Coder that died stays open until the command removes the file or the TCP connection breaks.

## 22. Limits and timeouts
Context: A bad client can open many connections and pay nothing, but finance pays 10000 MU for each tunnel connection.
Decision: Defaults: 16 open pipes, 10 s connect timeout, 14400 s (4 hours) idle timeout, 4096 bytes maximum for the destination file. The idle time counts both directions together. A connection in progress counts against the pipe limit. A connection over the limit closes at once with one log line. Half-close works: after one side sends EOF, the other direction has 30 s (--grace) to finish.
Reason: VS Code Remote-SSH opens several connections. SSH sessions can be quiet for hours. A shared idle clock stops a pipe only when both sides are quiet.
Effect: The values are settings (--max, --idle, --connect-timeout). Nobody tuned the values on a real node.

## 23. Strict host validation, no resolution rules
Context: The app user writes the destination file from the output of the node, but the forwarder must not trust it.
Decision: The host must be an IP address (v4 or v6). Or it must be a name of letters, digits, dots and hyphens that starts and ends with a letter or digit. The port must be an integer from 1 to 65535. The forwarder refuses other types (text, boolean, float). The match uses the whole string, so a trailing newline fails.
Reason: A bad file must not make the forwarder connect to an odd target or write odd text in the log.
Effect: The forwarder accepts loopback and private addresses. It refuses loopback, unspecified, link-local and multicast targets, and it checks the address that the connection reached, so a name cannot hide them. It does not limit the target to the Coder: the app user writes the file, and the Coder controls the app code (entry 86), so private addresses stay allowed.

## 24. Forwarder user sshproxy and a group on the coder directory
Context: The app user owns /var/lib/finance/coder (mode 750) and writes destino.json. The forwarder needs to read it. Group app is not suitable: group app can read /__config__.
Decision: The Dockerfile creates user and group sshproxy (uid and gid 1003, no login shell). Finance had the users app (1000), agente (1001) and chat (1002) only, so this user is new. start.sh sets the directory to app:sshproxy, mode 750. The file is mode 644 and the app user owns it. The forwarder starts through the "como" function with an empty environment (env -i).
Reason: The forwarder gets read access to this directory only. It cannot write the destination file or change the directory.
Effect: The forwarder can also read the other control files in this directory (PID files, flags, recarga.json, lanzando). They hold no secret. The panel step must write destino.json by atomic rename in the same directory.

## 25. Restart loop and place in start.sh
Context: Every piece that ends stops the whole service (wait -n), but the forwarder is not essential for the web app.
Decision: start.sh runs ssh_proxy_bucle, which restarts the forwarder after 3 seconds. TERM kills the child. The script and the user are outside the shared project, so a Coder cannot change them. The forwarder starts after the web and queue are ready and does not add a phase to the status page.
Reason: A crash of a debug path must not take down the finance service.
Effect: A crash loop shows in app.log. Nobody verified setpriv and the port bind at 2222 as a non-root user on a real VM (port above 1023, so the process needs no capability).

## 26. The host key comes from PHP sodium, not from ssh-keygen
Context: The image has no ssh-keygen. The Coder needs an OpenSSH private key in CODER_SSH_HOST_KEY.
Decision: App\Services\Coder\ClaveSshHost builds an ed25519 key in the OpenSSH file format with the sodium extension. Rejected options: add openssh-client to the Dockerfile (a package and a sub-process for one call) and PHP openssl (it cannot write the OpenSSH format).
Reason: Sodium is part of the PHP image. The format is short.
Effect: No image change. The test ClaveSshHostTest runs ssh-keygen when it exists.

## 27. The host key lives in a subdirectory that the app user owns
Context: /var/lib/finance/secretos was root only (mode 700). The panel code runs as the app user and must write the key there.
Decision: start.sh sets secretos to mode 711 and makes secretos/coder (owner app, mode 700). The key file is secretos/coder/ssh_host_ed25519, mode 600, and the panel writes it by rename.
Reason: The app user must enter secretos to reach its subdirectory. Mode 711 lets it enter and does not let it list. Files directly in secretos stay mode 600 for root.
Effect: Other users (agent, chat) can test if a name exists in secretos, but cannot read it. Finance makes the key at the first launch, not at start.

## 28. Spec reading is a replaceable class that returns "unknown"
Context: celaut v0.6.3 cannot read the spec of a service. The agreed rule says to refuse a service with no guest share "project".
Decision: App\Services\Coder\LectorEspecificacion returns null (unknown) for a service id. The default value of config coder.spec_desconocida is "rechazar": Coder::lanzar refuses a launch with a Spanish message. The value "confirmar" lets the user confirm in the panel that the service mounts the share. Rejected options: parse the .bee file in PHP (protobuf and multiblock code, too large) and a Python helper with bee-rpc (not in the image).
Reason: The rule stays strict. A real reader can replace the class later with no change in Coder.
Effect: With the default, a launch by hash is refused until a reader exists or the setting is "confirmar". Entry 88 adds a reader for uploaded packages. The open item for a reader of service ids is in TODO.md.

## 29. Coder state uses Ajuste keys with the prefix coder.
Context: The state must survive a restart of the web process and be readable by the panel, the job and the command.
Decision: Keys in `ajustes`: estado (none, launching, running, error), instancia, servicio, tipo, referencia, lease, uri, error, lanzado_en, claves_ssh and the three limits. The stop action sets the keys of one life to null and keeps claves_ssh. The values that change often (enviado_mu, horas_vida, saldo_mu, precio, ultimo_rechazo, the samples and the counters) are keys in coder_estado (entry 49).
Reason: Ajuste::fijar audits each change, and a state change must leave an audit row.
Effect: Each state change writes an audit row "ajuste.cambiado". Package files stay after a stop, so a new launch is quick. The nutrition log listed some of the volatile keys here as settings. The finance code follows entry 49.

## 30. The settings table is shared, so the Coder has an owner instance
Context: The table "ajustes" lives in the shared database. In finance it is not in the core schema, but two devices on one database see the same coder.* rows.
Decision: A launch stores coder.instancia = Instancia::id(). If the state is not "none" and the owner is another instance, estado() sets ajeno=true. Launch, stop and the watcher then refuse or skip.
Reason: A second device must not read a lease from another node and mark the Coder as dead, or stop it.
Effect: One Coder for each shared database at a time. A Coder of a lost device stays "ajeno" until the owner stops it. A manual change of coder.estado to none clears it. The table `ajustes` is also in the merge list (fusion_tablas()), so a merge of two databases copies coder.* rows too. The owner check stops a wrong action, but the merge can show a Coder state of the other device. This is a known limit.

## 31. Launch runs in a queue job and a stuck launch times out
Context: celaut acquire can take minutes. A web request cannot wait for it.
Decision: App\Jobs\LanzarCoder calls Coder::lanzar (tries 1, no timeout), on the default queue. Coder::lanzar sets the state to "launching" first. The watcher (finance:vigilar-coder) marks a launch older than coder.lanzamiento.colgado_s (2400 s) as "error". When a refusal occurs before any change, the job stores the reason in coder.error.
Reason: If the queue worker dies, the state must not stay "launching" for ever.
Effect: Nutrition recorded that a reload could kill a launch in progress. Entry 90 prevents this with a reload lock. A launch can still die for other reasons (a crash, a kill of the worker). Then the command gives "error" after 40 minutes, or the user stops with force. The lease of such a launch can stay in the pool. The button "Forzar la parada" shows in the state "launching" as well as in "error", so the user does not wait 40 minutes (a first test on a nutrition node showed this).

## 32. The SSH address is the uri of acquire, and the launch checks the SSH banner
Context: celaut gives no slot list for a child. `uri` is the first address of the first api slot.
Decision: The launch connects to the uri and reads a line that starts with SSH-2.0-. It retries for 180 s. If the test fails, the launch stops the service, sets the state to "error" and writes no destino.json. The contract says that the ssh slot is the first api slot.
Reason: A wrong first slot would send the user to a wrong service. The banner test needs no node command.
Effect: A Coder whose first slot is not SSH never gets a target. Finance makes the test connection to the child, and it costs nothing. Not verified on a node.

## 33. Rules for the authorized keys
Context: The panel writes its text in a file that the Coder reads as authorized_keys, as root in its VM.
Decision: ClavesSsh accepts a line only if it matches "type base64 [comment]" as a whole. Types: ssh-ed25519, ecdsa-sha2-nistp256/384/521, ssh-rsa. The blob must start with the same type name. The comment has 1 to 64 characters from A-Z a-z 0-9 @ . _ : + = - and space. ClavesSsh skips blank lines, equal keys count once, the maximum is 20, and one bad line rejects the whole text. Rejected option: keep the good lines and drop the bad ones.
Reason: A quote, a comma or a control character in the comment could add an option. Silent drops hide errors.
Effect: Comment lines with # and keys with options fail. The user sees the line number. An empty text is valid and removes all keys.

## 34. The code writes the keys at each save and at each launch
Context: The share is new at each start of finance, so .coder/authorized_keys does not survive. The key list is also in Ajuste.
Decision: guardarClaves writes the file by rename (mode 644) and then stores the list. Launch writes the file again from the list before it starts the service. If the write fails, the launch starts nothing.
Reason: A launch with no keys file would give a Coder that nobody can enter, and it would cost money.
Effect: The directory .coder in the share belongs to the app user (start.sh creates it with chown), and the app writes only this file there. The sshd of the Coder can refuse the file with StrictModes if the owner is wrong (contract requirement 8). Not verified on virtiofs.

## 35. Package upload rules
Context: The panel accepts a .celaut.bee file. A path from a form field must not reach celaut.
Decision: guardarPaquete accepts only a name that ends in .celaut.bee and a size up to coder.paquete.tamano_maximo_mb (512). It copies the file to coder/paquetes/<random>.celaut.bee (mode 600) and runs celaut inspect. Launch accepts a path only if its real path is inside that directory. A symbolic link to the outside fails.
Reason: The user cannot choose a stored file with a random name. The inspect call rejects a file that is not a package.
Effect: The PHP and Livewire upload limits are separate and must be at least 512 MB (entry 63). Finance never deletes uploaded files. The OCR package of the OCR panel is a different folder and a different path.

## 36. The confirm step shows the cost and the resource bound, not the real resources
Context: celaut cost prints four values and no memory, disk or CPU. The node refuses a cost for a service that it does not hold.
Decision: preparar returns the cost array (or null with the reason of the node), the admission bound from config coder.recursos, and two spec fields. The real resources are "conocidos: null".
Reason: A made-up value is worse than an unknown value.
Effect: For an uploaded package that the node never saw, the panel shows "cost unknown". Only the initial MU setting then bounds the start.

## 37. Stop closes the SSH access first, and a forced stop exists
Context: A stop that the node refuses must not leave the SSH target open.
Decision: parar removes destino.json before the node call. If the node refuses, the state becomes "error" and the lease stays, so a new stop can run, and Coder::lanzar refuses a new launch. The option $forzar clears the state anyway. A stop while the state is "launching" needs $forzar. A stop, also a forced one, removes the reload lock (entry 90).
Reason: Access closes at once. The user is never stuck with a lease that finance cannot stop.
Effect: A forced stop can leave a lease in the pool. The watcher does not track it any more.

## 38. A dead Coder gets the state "error" with no lease
Context: The watcher finds that the lease is not in the pool any more. The lease number has no use then.
Decision: The watcher removes destino.json and the reload lock, sets "error", stores the reason and clears lease and uri. The code allows a launch when the state is "error" and the lease is null. It does not allow a launch with an "error" that keeps a lease.
Reason: A stop of a lease that does not exist would fail for ever.
Effect: The panel shows the reason until the user launches again. If the node does not answer, the watcher changes nothing.

## 39. The watcher runs in start.sh every 60 seconds
Context: The Coder can die from lack of balance at any time. The forwarder keeps open pipes until destino.json changes.
Decision: start.sh runs finance:vigilar-coder as the app user in a loop with a 60 s pause. With no Coder, the command only removes a stale destino.json and makes no node call.
Reason: The load is small. The same command holds the funding rules (entry 55).
Effect: A dead Coder closes its SSH pipes in less than about 61 s. The command calls celaut list once for each pass when a Coder runs.

## 40. The service class is built from config in the container
Context: Nodo has string constructor arguments and the container cannot fill them from config.
Decision: AppServiceProvider binds Coder::class to Coder::desdeConfig(). (When this log was written, the finance AppServiceProvider did not hold this binding yet. Without it, the container builds Nodo with its default arguments and ignores the config.) Tests replace it with $this->app->instance(). Fakes are subclasses of Nodo, SondaSsh and LectorEspecificacion in tests/Support.
Reason: The command and the job get the class by injection and need no static call.
Effect: A fake node needs no shell script. NodoCoderProcesoTest tests the real process call. Finance already had Nodo (with apagar()); the port adds only the missing methods (adquirirPorHash, adquirirPorPaquete, costePorHash, costePorPaquete, idDePaquete).

## 41. One class for the money rules, with a pure core
Context: The rules must be easy to test. The code that moves balance needs the node, the settings and the audit log.
Decision: App\Services\Coder\Saldo holds both. The static method decidir takes plain values and returns permitted, reason, failed control and maximum amount. It reads no setting and calls no node. Saldo takes Nodo and Coder in its constructor.
Reason: A test of a pure method uses many cases and no fake. A test of the transfer code uses the fake node.
Effect: The panel step calls addBalance, guardarLimites and status. The command calls vigilar. Rejected option: put the rules in Coder, which already holds the launch code.

## 42. Setting keys for the panel
Context: The panel step binds to the settings.
Decision: The user edits three keys. They are coder.tope_mu (total cap, digit string, null means default), coder.porcentaje_max (integer 1 to 100) and coder.horas_minimas (integer 0 to 8760). The panel calls Saldo::guardarLimites, which validates and calls Ajuste::fijar. The defaults are in config coder.saldo: 24 hours of cap with margin 1.25, 20 percent, 48 hours.
Reason: One method validates all three values together. A bad value changes nothing.
Effect: Only Saldo writes the other keys: coder.muestras_saldo, coder.depositado_hijos_mu, coder.precio, coder.ultimo_rechazo, coder.saldo_mu, coder.saldo_finance_mu, coder.horas_vida and coder.enviado_mu (all in coder_estado). The panel only reads them, through status().

## 43. The code applies the cap rule as written
Context: The agreed rule says MU sent during this life plus the current balance must not pass the cap. Sent MU that is still in the balance counts twice.
Decision: I kept the rule as written. "Sent" means the top-ups only. The MU of the launch (initial balance) are not in it, but they are in the current balance.
Reason: We agreed the rule. The double count makes it stricter, never looser. With the default cap (24 hours with margin) the Coder lives about one day.
Effect: The real total that finance pays for one Coder is about half of the cap plus the launch MU. A user who wants more raises the cap in the panel.

## 44. The life control looks at the life after the transfer
Context: The agreed rule refuses when the life of finance now is under X hours. A transfer can push a healthy finance under X.
Decision: The control refuses when finance would keep less than X hours of burn after the transfer. It needs balance minus amount at least X hours times 3600 times the burn rate. With the life already under X, the maximum is 0.
Reason: The goal of the control is that finance never goes under X because of the Coder. The check on the life now would not give that.
Effect: The rule is stricter than the agreed one. The boundary is exact: life of exactly X hours after the transfer passes. The check uses integer MU, so the rounding goes up (more MU kept).

## 45. Maximum amount, and a smaller transfer in the loop
Context: A refusal for the full target would leave the Coder without balance although the rule allows part of it.
Decision: decidir always returns the largest amount that passes the three controls. addBalance never changes the amount: if it is too large, it refuses and says the maximum. The loop sends the maximum instead of the target when the maximum is at least one hour of maintenance (or the whole gap, if smaller).
Reason: A person who types an amount expects that amount or a clear refusal. The loop has no person: a partial top-up is better than none, but a transfer of a few MU each pass is noise.
Effect: A refusal of the loop means that even one hour does not fit under the controls.

## 46. Burn rate from balance samples, not from the ledger
Context: celaut account with --from-seq gives the movements, so the rate of the maintenance rows is exact. Nodo has only the cheap read (head and balance).
Decision: I used samples as the agreed design says. A sample is time, finance balance and the total that finance deposited in its children. Burn between two samples is balance fall minus the deposit delta. Rate is total burn divided by total time.
Reason: It needs no new Nodo method and no parsing of unverified JSON. A read costs about 100 MU, and the ledger read costs 100 MU plus one per row.
Effect: The estimate includes every cost of finance, such as tunnel opens and the OCR child. It is noisy for a few samples. Rejected option for now: the ledger method. It can replace tasa() later with no change to the controls.

## 47. The deposit counter covers Recarga too
Context: finance also deposits balance into the PostgreSQL child (App\Services\BaseCompartida\Recarga). Those MU would look like burn.
Decision: One counter, coder.depositado_hijos_mu, counts every deposit of finance. Saldo and Recarga both add to it. Recarga calls Saldo::anotarDeposito inside a try block, so an error there never stops the database top-up.
Reason: The rate must not depend on who deposited.
Effect: The code reads the counter before the balance. A deposit between the two reads can only raise the burn of one interval. The next interval then shows a negative burn, and the code drops it. The read-and-add of the counter is not atomic across processes. The error is small and goes to the safe side. The OCR child gets its balance from its own start, not from a deposit, so it is not in the counter.

## 48. When the rate is unknown
Context: Too few or bad samples must never allow a transfer.
Decision: The rate is unknown (null) in these cases. There are fewer than 4 samples. The samples span less than 600 s. Fewer than 3 intervals are valid. The rate is 0 or negative. The code drops an interval with no time or a negative burn (a refund, a credit). All limits are in config coder.saldo.
Reason: A refund or a credit hides burn. With a long list one dropped interval is acceptable. With the minimum list it is not.
Effect: After a launch, the first top-up waits about 15 minutes. The initial balance of the launch covers that time (about 1 hour by default). The list keeps at most 72 samples and drops those older than 12 hours.

## 49. Volatile values live in the table coder_estado
Context: Ajuste::fijar writes an audit row on each change. A sample every 300 s, with the whole list, would fill the audit log.
Decision: Saldo and Coder write samples, counters, balances, price and the last refusal in the table coder_estado (model CoderEstado, key and json value). The migration is 2026_01_04_000010_create_coder_estado_table.php. This table has no audit trigger, and the backup skips it. The three limits and the launch values still use Ajuste::fijar (the table ajustes has an audit trigger).
Reason: The audit log is for decisions and money moves, not for readings.
Effect: Each transfer and each refusal has its own audit row (see 53). A changed limit has the standard setting row. See entry 92 for the schema, audit, backup and merge rules.

## 50. Price source and default cap
Context: The default cap is 24 hours of maintenance at the current price. celaut cost gives three values and no resources.
Decision: The price is max_maintenance_cost_mu of the running service (one period of 10 s at the largest resources). The class stores it in coder.precio with its read time and reads again after 600 s. Default cap = price times 8640 periods times 1.25. The hours left of the Coder use the same price. The panel view uses stored values only.
Reason: The largest price is the safe bound. A page view must not wait for the node.
Effect: Hours left of the Coder are a lower estimate. Until the first read, the cap is unknown and the loop sends nothing. If the node refuses the price read, the old price stays. The period (10 s) comes from config finance.recarga.periodo_s; a real period can last longer, so the hours are an estimate.

## 51. Target and threshold of the loop
Context: A deposit on each pass would add an audit row every 5 minutes.
Decision: The loop aims at 12 hours of maintenance (config coder.saldo.objetivo_horas). It tops up only when the balance is under half of the target. It then sends the gap to the target, limited by the controls.
Reason: The target is under the default cap, so the loop alone does not use it all. A threshold gives few and larger transfers.
Effect: The Coder balance goes between 6 and 12 hours. A user who wants more adds balance by hand.

## 52. Record first, deposit second, roll back on failure
Context: A crash between the node call and the record would let the code pass the cap again.
Decision: enviar adds the amount to coder.enviado_mu and to the deposit counter before the call to celaut deposit. If the node refuses (success false) or does not answer, it subtracts the amount again.
Reason: A crash can only make the counters too high. A counter that is too high only makes the controls stricter.
Effect: A failed deposit uses no cap. It has an audit row (coder.saldo_fallo) and shows in the panel as the last refusal with the control "deposito".

## 53. Audit events and silence for the loop
Context: The code must audit every refusal. A loop that the controls refuse for the same reason would write a row every 300 s.
Decision: Events: coder.saldo_enviado, coder.saldo_rechazado (control, reason, amount, source, maximum, balances) and coder.saldo_fallo. The code always audits a refusal of a user action. It audits a refusal of the loop in two cases. The control or the source changes. Or the last audit of it is older than one hour. The code always stores the last refusal for the panel.
Reason: The log keeps every change of reason and every hour of a lasting refusal. It does not fill with copies.
Effect: With a refusal that lasts a day, the log has about 24 rows from the loop. This is a small step away from "every refusal" and it is only for repeated loop refusals.

## 54. A lock for all transfers
Context: The panel (web process) and the loop can decide at the same time on the same balance.
Decision: addBalance and topUpToTarget run inside Cache::lock("coder-saldo", 180 s). A busy lock gives a Spanish error for the panel and the action "ocupado" for the loop. If the cache cannot give a lock, the code runs without it.
Reason: Two transfers with the same balance read could pass the cap together.
Effect: Both processes run on one VM and share the database cache. A lock that a crash leaves is free after 180 s. Do not confuse this lock with the reload lock (entry 90).

## 55. Two loops in start.sh
Context: A dead Coder must close its SSH pipes in about one minute. The funding pass can run every 300 s, like vigilar-base.
Decision: The command finance:vigilar-coder has the option --saldo. Without it, it only checks the lease (loop of 60 s). With it, it checks the lease and then samples and funds (new loop of 300 s). Both loops end with "|| true" and the command always exits with success. Errors go to the output.
Reason: One command keeps the logic in one place. Two periods match the two needs.
Effect: The first funding pass runs at start, not after 300 s. The node gets one celaut list more per 300 s.

## 56. Samples before and after a launch or stop
Context: The launch charge (initial balance and build cost) leaves the finance balance in one step. It is not burn.
Decision: Saldo samples at every pass, with or without a Coder. The launch code clears the samples after the node accepts the start. Stop and launch clear the last refusal and the stored price.
Reason: Samples before the launch would show a large false burn. With no Coder the read costs about 100 MU per pass, and it gives a rate before the first launch.
Effect: Saldo loses a sample that it takes during a long launch. After a stop, the code drops the refund credit as a negative interval.

## 57. Number limits and input rules
Context: The amounts come from the panel, from the node and from settings. PHP integers have 64 bits.
Decision: Every amount is an integer or a string of digits. The rule cuts a balance above 10^15 MU to 10^15. It refuses a request above 10^15 as too large. It also refuses signs, decimals, exponents, spaces inside and non-text values. It cuts the percentage to 0 to 100.
Reason: With these limits no product or sum can overflow. A refusal is always better than a wrong number.
Effect: The rule checks the control "cantidad" before all others. This control has its own reason text. The three real controls keep the agreed order.

## 58. One page, three services, no rules in the page
Context: The page must start, stop, fund and reload the Coder. The rules already exist in Coder, Saldo and the watcher.
Decision: CoderPagina (App\Filament\Pages) only reads input and calls Coder, Saldo and ControlRecarga. It has no money rule and no launch rule of its own. Class name: CoderPagina, because the service already uses the name Coder. Group Ajustes, slug coder, sort 12. It uses the Filament v5 API (checked in vendor/filament, as the finance AGENT.md requires).
Reason: One rule in one place. A rule copy in the page would drift.
Effect: The page checks the guest tag before the launch only to hide the button. Coder::lanzar has the last word and the job refuses again.

## 59. ControlRecarga holds the watcher files
Context: Every caller must write and read the flags and recarga.json the same way. The page should stay thin.
Decision: App\Services\Coder\ControlRecarga writes auto-recarga, recargar-ahora and restaurar-original, and reads recarga.json. It reuses Coder::escribirAtomico, which is public static. Rejected: a second atomic writer.
Reason: The step asked for no duplicate logic. The write by rename already has a test.
Effect: The code never writes half a flag. A missing control directory gives a Spanish error and no crash.

## 60. recarga.json is untrusted input
Context: A crash or a Coder with write access can leave a missing, partial or wrong recarga.json. The page prints some of its text.
Decision: ControlRecarga::estado gives a default for each field. It checks each type and keeps only known states and results. It cuts the message to 400 characters, removes control characters, and accepts a tree hash only as 64 hex characters. The class ignores a file over 64 KiB. Blade escapes the text. The field espera_lanzamiento is true only if the value is the JSON boolean true.
Reason: The page must render in all cases, and a field must never become markup.
Effect: Without a valid file, the page says "sin datos" for the watcher. It still works.

## 61. Locked properties and a second check on confirm
Context: A browser can change every public property of a Livewire page.
Decision: preparacion and rutaPaquete are #[Locked]. The confirm step runs Coder::preparar again, checks the block rule again, and dispatches the normalized reference from that result, never the typed text. Rejected: trust the stored preparation.
Reason: The cost and the spec result can change between the two steps. A client must not fake a clean preparation.
Effect: One more node call at confirm. A client cannot skip the block rule. The package path still goes through Coder::referencia, which accepts only files in the packages folder. The exception for a changed locked property is Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException (the tests use this class).

## 62. Upload path: Livewire upload, random name, no client path
Context: The user uploads a .celaut.bee file up to 512 MB.
Decision: The page uses WithFileUploads and a plain file input. On upload it calls Coder::guardarPaquete with the temporary file and the client name. The client name only decides the extension check. The stored name is random. The page deletes the temporary upload at once, also after an error. Rejected: the Filament FileUpload field (it needs a form and a disk for a file that must not stay on a public disk).
Reason: guardarPaquete already checks extension, size and the id.
Effect: The client file name never reaches the file system. Tests use UploadedFile::fake()->createWithContent(...). The method create(name, kb) makes an empty file.

## 63. Upload limits in four places
Context: The default limits are too small. Livewire allows 12 MB. Finance already raised PHP and Caddy for the OCR package.
Decision: config/livewire.php (rule max 512 MB, upload time 15 minutes) is new. The PHP ini has upload_max_filesize 1024M and post_max_size 1030M (they were already above 512 MB for the OCR package). Caddy has request_body max_size 1100MB for the whole :8080 site (it was already there). The PHP memory limit stays 512M.
Reason: Finance has a large upload for the OCR package already, so no path rule is needed. The nutrition port limits Caddy to the Livewire upload path with 520 MB; finance does not, because the global limit is older and higher.
Effect: Four numbers must stay in order (coder.php 512, livewire.php 512, PHP at least 512, Caddy above both). The Livewire version is v4.2.1 (from Filament 5.3.1). The upload route is named livewire.upload-file in its source. Nobody tested a real upload of 512 MB through FrankenPHP.

## 64. Cost and resources shown at the confirm step
Context: celaut cost gives no resources. For a package that the node does not hold, it gives no cost.
Decision: The step shows the three cost values and the admission bound (3 GiB, 10 GiB, 2 CPU) as "up to". If the cost is unknown, it says so and offers an optional field for the start bound in MU (--initial-mu). The field is empty by default.
Reason: The user can then limit the start cost when the node cannot price it. A default bound would need a price, and the price is unknown.
Effect: With no bound and no cost, the node uses its default (cost plus about 1 hour). The text warns the user.

## 65. Actions with a confirm modal
Context: Money, a stop and a restore are hard to undo.
Decision: Launch, stop, force stop, add balance and restore original are Filament actions with requiresConfirmation and a text that names the effect. Add balance has an amount field. Reload now has no modal (the watcher checks the code first). Limits and keys use buttons, with no modal. The auto reload switch applies at once, and the code audits it (coder.recarga_auto).
Reason: A modal is for what the user cannot undo. A reload is safe by design.
Effect: Each action is testable by name with callAction.

## 66. Error handling: one wrapper
Context: The node can fail, time out or refuse. A page crash would hide the controls.
Decision: Every action runs in `protegido`. CoderException and NodoException become a red notification with the message. The page reports any other error to the log and shows a short generic notice. The view data reads the state and the balance with `seguro`, with a fallback.
Reason: The page must stay usable when the node is down.
Effect: A page view makes no node call. It reads stored values only (see entry 50).

## 67. Polling only while launching
Context: A launch runs minutes in the queue. Constant polling can erase text that the user types in a field that the user did not send yet.
Decision: The page polls every 5 s only in the state "launching". In that state the page hides the launch form.
Reason: The user loses no typed text. The user still sees the end of the launch.
Effect: In other states, the user must reload the page to see a change of the watcher or of the balance. The action buttons refresh the page.

## 68. Connect block text
Context: The page does not know the node name of this instance.
Decision: The command uses the placeholder <instancia-de-esta-app>. The listen port is the same as the slot port (2222). The ssh config has HostKeyAlias finance-coder, because the host key stays the same across launches and the address is always 127.0.0.1. The page warns that each tunnel connection costs MU.
Reason: Without an alias, a second tunnel on the same port would raise a host key warning. The cost note comes from the node docs (NETWORKS and USAGE).
Effect: The user replaces one placeholder. The alias differs from nutrition (nutrition-coder), so a user of both projects can keep both in one ssh config.

## 69. Confirm mode for an unreadable spec
Context: The default setting refuses a service when the spec cannot be read (entry 28).
Decision: The page follows the setting. With "rechazar" it shows the reason and no launch button. With "confirmar" it shows a box that the user must tick, and passes that choice to the job.
Reason: The page must not be a way around the setting.
Effect: With the default setting, a launch by hash is refused until the setting is "confirmar". A launch of an uploaded package works, because entry 88 reads its spec.

## 70. One parent share with the tag project
Context: The Coder and finance must see the same code tree. The node offers shares in two forms: a parent share and a network share.
Decision: Finance exports one parent share at /opt/finance/project with the tag "project" (service.json, role "shared", access rw). The Coder mounts it as a guest.
Rejected options: A network share (see entry 71). One share for each directory (it needs more tags and more checks).
Reason: A parent share needs no third service and no network. The tag, not the path, identifies the share. The Coder can mount it at any path.
Effect: The Coder must be a direct child of finance on the same node. The share belongs to finance (see entry 85).

## 71. No network share
Context: A network share can outlive both services and can join services that are not parent and child.
Decision: The feature does not use a network share.
Rejected options: A network share between finance and the Coder.
Reason: A network share needs an extra owner. It has more states, and a failure there is hard to see. The parent share has one owner and one life.
Effect: The share loses the code when finance stops (see entry 85). The Coder keeps its work in git.

## 72. No control API between the Coder and finance
Context: The Coder must tell finance when to reload.
Decision: The Coder sends nothing. Finance polls the share (entries 10 and 14).
Rejected options: A control API on finance. Request files that the Coder writes in the share.
Reason: A control API is an attack surface that the Coder could abuse. A request file is a second control path that the Coder writes and root reads. A poll needs no trust in the Coder.
Effect: A reload starts up to 5 seconds plus the debounce after the last edit. Any editor works, with no special tool.

## 73. Auto reload is ON by default
Context: The reload checks the code before it acts, and it can roll back.
Decision: A missing flag file means ON. The user can turn it OFF in the panel. Then the watcher waits for "Reload now".
Reason: The aim is to edit while the server runs. With ON, an edit is live at once. With OFF, the user keeps full control.
Effect: A bad edit can start a reload. The checks and the rollback limit the damage (entries 15 and 18).

## 74. A last good snapshot and an automatic rollback
Context: A change can pass the syntax and boot checks and still fail at run time.
Decision: After each good reload the watcher saves a small tar of the code directories. If the health check fails, the watcher restores it.
Reason: The user can then use the app after a bad edit. The Coder is not always a person who can fix the code at once.
Effect: One snapshot only. The snapshot has no vendor. The watcher does not roll back a bad change to vendor. Use "Restore original" for that case. The health check tests only /up, so a broken Filament page can pass it.

## 75. SSH is the only access path
Context: The user needs a shell, an editor and a file view in the Coder.
Decision: Finance forwards SSH bytes on port 2222. The user reaches it with `nodo tunnel`.
Rejected options: An HTTP proxy to a web editor. A proxy for the chat. Both need finance to parse or to serve the protocol of the Coder.
Reason: SSH carries every tool: a shell, VS Code Remote-SSH, git and port forwarding. The forwarder pipes bytes and never parses them.
Effect: The slot has the tag ssh. The Coder must follow the contract (entries 32 and 79).

## 76. The service hash is free and not fixed
Context: The author of a Coder service can publish new versions at any time.
Decision: The panel accepts any hash or an uploaded package. No hash is in the code or in the config.
Rejected options: A fixed hash in config. A list of allowed hashes.
Reason: A fixed hash would need a new release of finance for each Coder version. The user takes the risk, and the confirm step and the funding controls limit it.
Effect: Finance cannot trust the service. The contract is the only link (entry 79). See also entry 28 for the spec check.

## 77. Funding controls and their defaults
Context: A Coder spends MU that finance owns. A Coder with no limit can drain finance.
Decision: Three controls run in this order: total cap, percentage of the joint balance, minimum life of finance. The defaults are: cap of 24 hours at the current price with a margin, 20 percent, 48 hours.
Reason: The cap limits the total loss. The percentage limits the loss at one time. The minimum life keeps finance alive. The order is from the widest limit to the most specific one.
Effect: The rules are in Saldo::decidir (entries 41 to 45). The user can change the three values in the panel. The database child has its own 30 percent rule (README of this folder).

## 78. Rules for SSH keys
Context: The text in the panel becomes authorized_keys in the Coder VM.
Decision: Only five key types are valid. No key options are valid. The maximum is 20 keys. The code writes the file by rename.
Reason: A key option such as `command=` can run a command or open a port. A strict list closes this risk. A rename never gives a half file.
Effect: See entry 33 for the exact rules. The Coder reads the file at each login, so a change needs no relaunch.

## 79. The host key is made once and stays the same
Context: A new host key at each launch shows a warning to the user and breaks `known_hosts`.
Decision: Finance makes the host key at the first launch. It stores the key in /var/lib/finance/secretos and passes it in CODER_SSH_HOST_KEY.
Reason: The address is always 127.0.0.1 through the tunnel, so the key is the only identity check.
Effect: Finance stores the key in the finance VM, so the key stays when finance restarts only if the disk stays (see TODO.md). The key appears in the argument list of the celaut process for a short time. It allows host impersonation only.

## 80. Finance launches the Coder with a reusable configuration
Context: The node reuses an idle instance with the same service and the same configuration.
Decision: The launch passes the host key as the only environment variable. It never uses --new.
Reason: A stable configuration lets the node reuse an instance, and the lease stays in the pool until finance stops it.
Effect: Finance must not release the lease of the Coder. The node needs a leased lease for metrics, deposit and tunnel.

## 81. The stop action does not trust the node
Context: In nutrition, nothing cascades when the service stops. The node does not stop the children. Finance differs: start.sh runs `finance:apagar-nodo` (`celaut shutdown`) at the end, and it stops the children that finance started.
Decision: The panel stop action closes the SSH target first and then asks the node to stop. See entry 37. Keep finance:apagar-nodo as it is.
Reason: A user can then stop the Coder before the shutdown of finance. The shutdown command is a second line of defense.
Effect: A clean stop of finance (TERM to start.sh) should stop the Coder with the other children. Nobody verified this on a node, and the command does not run after a crash or a kill of the VM. The open item (the notice declaration, celaut notice --shutdown) is in TODO.md.

## 82. Documentation language
Context: The project text is Spanish. The Coder docs, comments and commits need a clear style for a reader with a different native language.
Decision: New documentation, code comments and commit messages for this feature use ASD-STE100 English. UI text stays Spanish. The Coder section of the main README is Spanish, like the rest of that file.
Reason: The panel is for Spanish speakers. The docs and the code are for developers and for the authors of other services.
Effect: This decision does not change existing Spanish comments in other files.

## 83. One scope for the Coder
Context: Nutrition has one household, and Hogar::unico() returns it. Finance has no household model.
Decision: The Coder page has no picker. The Coder state is global for the database.
Reason: A picker would add code for a case that does not exist.
Effect: The Coder state is global for the database (see entry 30).

## 84. Burn rate measured by samples, ledger method kept for later
Context: Entry 46 explains the choice. The ledger method is exact.
Decision: Keep the samples. Record the ledger method in TODO.md as a later improvement.
Reason: The samples need no unverified output. The ledger method needs a live node to check the JSON.
Effect: The estimate of the hours left can be wrong when the samples are few. The controls send nothing in that case.

## 85. The share is not durable
Context: The node owns the share through the finance instance. It makes the share new at each start.
Decision: The docs say that finance holds no copy of the live tree. The Coder must keep its work in git. Finance makes no automatic backup of the share.
Rejected options: A periodic copy of the share to the database. It would store code of unknown size and trust.
Reason: Git in the Coder VM is the standard way to keep code. The snapshot of entry 74 is for rollback only, not for backup.
Effect: A stop of finance loses the edits that the Coder did not push. The README of this directory says so.

## 86. Trust model: the Coder is in the trust domain of the app
Context: The Coder edits PHP files that the app runs as the user app, with the full environment of the app. Entry 2 must not suggest that the Coder is a separate trust domain.
Decision: Say it plainly in the docs and in the panel: after a reload, the Coder code can read all secrets of the app, read and change the database, spend the balance and run celaut as the app user. In finance the secrets include: APP_KEY, the database password, CHAT_SECRETO, AGENTE_SECRETO, the content of /__config__ (FINANCE_RED), the Claude token (claude-token.enc, encrypted with APP_KEY, so the app code decrypts it), the settings and the uploaded package of the OCR provider, and all financial data (expenses, banks, assets, crypto, investments). The funding controls stop an honest use only. The rules about root scripts, PID checks and symlinks protect the root part of the VM and the users agente, chat and sshproxy. The panel shows a warning before each launch.
Reason: A user who reads "separate trust domain" can launch an unknown Coder and lose the secrets and the financial data.
Effect: Launch only a Coder that you trust fully. The host key in the argument list of the celaut process (up to 30 minutes) is visible to the app user, which already can read all secrets. start.sh mounts /proc with hidepid=2, so agente and chat cannot see it. The Claude token that the agent uses (CLAUDE_CODE_OAUTH_TOKEN) is a subscription token: a leak has a cost outside the node. Rejected option: run the Coder code in a second PHP process with fewer rights (large change, not done).

## 87. Safety rules of the reload watcher
Context: The watcher runs as root. The app user and the Coder write the share and the control directory.
Decision: The watcher (a) kills only a PID that passes the owner and command line check, (b) removes symlinks before an extract, (c) clears setuid and setgid bits in the share before each check and after each extract, and start.sh does it at start and warns when the share has no nosuid, (d) refuses to hash or reload when the watched paths hold more than 200 MiB (RECARGA_MAX_BYTES), (e) writes only printable ASCII in recarga.json, (f) does not save a snapshot when a watched path holds a symlink, so a snapshot never writes through a link, (g) reads the reload lock as a regular file only, with digits only and a short read (entry 90).
Reason: Each of these is a way for a file or a PID that the Coder controls to make root act on a wrong target.
Effect: A larger share needs a higher RECARGA_MAX_BYTES. The check does not stop the Coder from filling the disk through other paths.

## 88. The spec reader reads uploaded packages, not service ids
Context: Entry 28 left LectorEspecificacion with a null result, so no launch could pass the rule "the Coder declares a guest share with the tag project". The celaut CLI v0.6.3 cannot read the spec of a service: `inspect` gives only the id, and `service-info` gives data from the chain.
Decision: For an uploaded package (.celaut.bee), the class scans the file in reads of 1 MiB with an overlap of 1024 bytes. It looks for the text of a share policy: `setting=share` (the end of the formal), two or three bytes of the protobuf field, then the value (`access`, `env`, `network`, `role`, `tag`, one `key=value` line each, sorted by key). It reports the tag of each entry with `role=guest`. A package with no such entry gives an empty list, and the rule refuses it. For a service id, the result stays null (unknown). Rejected options: a full parse of the protobuf stream in PHP (the filesystem is a multiblock and the policy is at the end of a file of 30 MB or more), and a call to the node (no command exists).
Reason: A packed service keeps the policy as plain text, so a scan is small, fast and testable. The scan can give a false guest share if a file of the image holds the same text after `setting=share`. This does not matter for safety: the node alone decides what the Coder mounts, and it refuses a launch whose guest share the parent does not export (SHARED_FILESYSTEMS.md). The worst case is a Coder that cannot see the code.
Effect: To launch a Coder from the panel with the default setting, upload its package. To launch by hash, set coder.spec_desconocida to "confirmar". A guest share with no tag is named by its path, so the reader does not report it. A package that stores the policy in another form (a future packer version) gives an empty list and the launch is refused. The nutrition log says that the scan was tested on a real package of the minimal Coder. The finance port runs the same code against test packages only.

## 89. The watcher opens the share again when the node replaces it
Context: In the first test on a nutrition node, the app answered 403 for every path as soon as the first Coder launched. The pages of the status server and the Caddy 404 still worked. On the host, the directory that backs the share was created at the moment of the launch, with mode 700 and owner root.
Decision: Each pass of the watcher (5 s) calls asegurar_acceso. If the root of the project is not readable and searchable for others, or the app user cannot read artisan, the watcher runs chmod a+rx on the root and chmod -R a+rX on the tree. It writes one log line each time that the problem starts. The test in docker/pruebas/test-recarga.sh covers a root with mode 700, one log line for two passes, and a tree with mode 600 files.
Reason: We infer that the node replaces /opt/finance/project with a new mount when the first child starts, and that the root of the new mount is mode 700 and owned by root. The app user then cannot enter it. This was seen on a nutrition node. Finance has the same mount model, so the same risk applies. Nobody has seen it on a finance node yet.
Effect: The app recovers within about 5 s. During this time the web server answers 403. start.sh still opens the permissions at start. If the node changes the owner of the files as well, a chmod can be not enough: check this with an SSH login to a Coder.

## 90. The reload lock protects a launch in progress
Context: A reload sends TERM to the queue workers. The launch of a Coder runs in a queue job on the default queue (entry 31). A reload during the launch killed the job, and the state stayed "launching" until the watcher gave "error" after 40 minutes. Nutrition saw this risk. Finance has the same risk, and OCR jobs on the ocr queue run for minutes too.
Decision: The app writes the file /var/lib/finance/coder/lanzando. The content is the unix time in seconds. The mode is 0644. The app writes it by rename, like destino.json. Coder::lanzar writes it when it sets the state to "launching". The app removes it in these cases: the launch ends (success, failure or exception: a try/finally block), the Coder stops (parar, also forced), and finance:vigilar-coder marks a stuck launch as error. The watcher starts no reload while the file exists and is younger than 2400 s (config coder.lanzamiento.colgado_s, RECARGA_LANZAMIENTO_MAX in the script). This holds for the automatic reload, the flag recargar-ahora and the restore of the original. The change stays pending and the flags stay in place. The watcher writes one log line each time that it starts to wait, and it reloads when the file is gone or old. recarga.json has the new field espera_lanzamiento (true while a reload waits). A launch that starts while the checks run also holds the reload: the cycle ends with the change still pending. start.sh removes the file at start.
Reason: A reload must not kill a launch. The wait is short in normal cases (a launch takes minutes), and the age limit makes sure that a lost file never blocks the reload for ever. The same limit as the stuck-launch timeout keeps one number for both.
Effect: A user who edits code with the Coder during a launch sees the reload after the launch. The panel text says that the reload waits. The watcher reads the file as root, so it accepts a regular file only (no symlink, no FIFO), reads at most 32 bytes with a time limit of 2 s, and counts the lock as stale (it never blocks) when the content is not a number or the time is in the future. The same rule holds in personal-nutrition. The app user can still delay a reload for up to 2400 s by writing a fresh valid time. The Coder can do this too (entry 86). Tested: the bash test (docker/pruebas/test-recarga.sh, cases 11 to 11d) and the PHP test of the Coder service. Not tested on a node.

## 91. The watcher takes the app folder from a setting
Context: Nutrition keeps Laravel in admin/. Finance keeps it at the repository root. Both projects use the same watcher script.
Decision: recarga.sh has the setting RECARGA_SUBDIR (variable APP_SUBDIR in the script). Empty means that Laravel is at the project root. A value such as admin prefixes every watched path, the boot check folder and the paths that a restore prunes. Finance leaves it empty. The watched paths for finance are app, config, routes, resources, database, lang, bootstrap/app.php, bootstrap/providers.php and composer.lock. The script skips a path that does not exist.
Reason: Two copies of the script would drift. A setting costs a few lines.
Effect: The bash test checks both cases (APP_SUBDIR=admin and empty). The project root is also the folder of the artisan command, so the watcher needs no other path.

## 92. coder_estado stays out of the schema, the audit, the backup and the merge
Context: Finance publishes a database schema on the network. database/esquema.json is generated from database/esquema-nucleo.json (the stable core tables) by the CI workflow .github/workflows/esquema.yml and by database/sincronizar-esquema.py. Finance audits tables with PostgreSQL triggers (migration 2026_01_02_000030) and merges data with fusion_tablas(). The new table coder_estado holds volatile values (entry 49).
Decision: coder_estado is not in the core (database/esquema-nucleo.json lists categories, establishments, expenses and expense_items). It gets no audit trigger. CopiaBase::OMITIR lists it, so the backup skips it. It is not in fusion_tablas(), so export and merge skip it. TablasAuditadasTest lists it as a table with no trigger, and gives the reason.
Reason: A schema change for a local table would force a new network schema. An audit row for each sample would fill the audit log. A backup or a merge of balance samples of one node is wrong for the other nodes.
Effect: The migration changes no byte of database/esquema.json, because the file holds the core tables only. The generator reads the core list, so a table outside it cannot enter. The exact check (migrate on a real PostgreSQL, then finance:esquema, then the sync script with --comprobar) belongs to the build step. This file does not record its result. The table ajustes is different: it is audited and it is in fusion_tablas(), so the coder.* rows in it are audited and merged (entry 30).
