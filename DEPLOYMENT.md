# Production deployment

The Docker stack contains Apache/WordPress and a private MariaDB service. The VPS host's existing Nginx proxies the site from ports 80/443 to WordPress on `127.0.0.1:8080`. The SQL dump is imported automatically only when `db_data` is empty.

## First deployment

1. Install Docker Engine with the Compose plugin on the VPS.
2. Copy this project to the VPS and create `.env` from `.env.example`. Use unique random values; never commit `.env`.
3. Allow inbound TCP ports 80 and 443 in the VPS firewall/security group. Do not expose ports 3306 or 8080.
4. Validate and start:

   ```sh
   docker compose config --quiet
   docker compose up -d
   docker compose ps
   docker compose logs --tail=100 db wordpress
   ```

5. Point the DNS `A` records for `gripcosaudia.com` and `www.gripcosaudia.com` to the VPS public IPv4 address. If IPv6 is configured, point matching `AAAA` records too; otherwise remove stale `AAAA` records.
6. After DNS resolves, issue the certificate using the host's Certbot and verify:

   ```sh
   sudo certbot --nginx -d gripcosaudia.com -d www.gripcosaudia.com
   curl -I https://gripcosaudia.com
   ```

## Database behavior

MariaDB imports `mwp_db/db_dom274807.sql` as UTF-8 on the first start of a new `db_data` volume. It will not re-import it on later restarts. To inspect import progress, run `docker compose logs -f db`.

Do not run `docker compose down -v` on production: `-v` deletes the database volume. Back up both the database and `wp-content/uploads` before upgrades.

The VPS installation includes a one-minute upstream watchdog and daily consistent database backups retained locally for 14 days in `/var/backups/gripco`. Local backups do not protect against total VPS loss: replicate `/var/backups/gripco` and `wp-content/uploads` to independent object storage or another server.

## Pre-DNS test

From a workstation, replace `VPS_IP` below with the server address. `--resolve` tests the production hostname against the new server without changing public DNS:

```sh
curl -I --resolve gripcosaudia.com:80:VPS_IP http://gripcosaudia.com
```

HTTPS becomes valid after public DNS points at the VPS and Certbot has issued the certificate.
