#!/bin/sh
# The hidden-service private key lives in the tor_data volume (never shared). Only the
# public .onion name is copied to /mirrors, which the site reads to show on the homepage.
chown -R tor /var/lib/tor
chmod 700 /var/lib/tor
( while [ ! -s /var/lib/tor/hs/hostname ]; do sleep 2; done
  tr -d '\n' < /var/lib/tor/hs/hostname > /mirrors/onion
  chmod 644 /mirrors/onion ) &
exec su-exec tor tor -f /etc/tor/torrc
