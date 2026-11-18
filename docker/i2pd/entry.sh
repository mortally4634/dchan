#!/bin/sh
# Private keys stay in the i2pd_data volume. Only the public .b32.i2p name is written
# to /mirrors, which the site reads to show on the homepage.
# The name is taken from the server tunnel's own cached destination file
# (destinations/<b32address>.N.dat) — deterministic and tied to imgboard.dat, unlike
# scraping the web console, which can list a transient client destination first.
chown -R i2pd /var/lib/i2pd
( until f=$(ls /var/lib/i2pd/destinations/*.dat 2>/dev/null | head -1); [ -n "$f" ]; do sleep 5; done
  b=$(basename "$f" | grep -oE '^[a-z2-7]{52}')
  [ -n "$b" ] && printf '%s.b32.i2p' "$b" > /mirrors/i2p && chmod 644 /mirrors/i2p ) &
exec su-exec i2pd i2pd --conf /etc/i2pd/i2pd.conf --tunconf /etc/i2pd/tunnels.conf --datadir /var/lib/i2pd
