FROM alpine:3.20
RUN apk add --no-cache tor su-exec
COPY docker/tor/torrc /etc/tor/torrc
COPY docker/tor/entry.sh /entry.sh
# Normalize line endings: a CRLF checkout on Windows would break the shebang.
RUN sed -i 's/\r$//' /entry.sh /etc/tor/torrc && chmod +x /entry.sh && mkdir -p /var/lib/tor /mirrors
ENTRYPOINT ["/entry.sh"]
