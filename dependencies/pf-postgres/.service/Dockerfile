# pf-postgres — PostgreSQL de personal-finance como servicio Celaut independiente.
# Solo se exporta el filesystem; CMD/ENTRYPOINT se ignoran (ver service.json → init).
FROM postgres:18-trixie

COPY start.sh /usr/local/bin/pf-postgres-start

RUN chmod +x /usr/local/bin/pf-postgres-start
