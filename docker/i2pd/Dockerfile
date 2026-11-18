FROM alpine:3.20
RUN apk add --no-cache i2pd su-exec
COPY docker/i2pd/i2pd.conf docker/i2pd/tunnels.conf /etc/i2pd/
COPY docker/i2pd/entry.sh /entry.sh
# Normalize line endings: a CRLF checkout on Windows would break the shebang.
RUN sed -i 's/\r$//' /entry.sh /etc/i2pd/i2pd.conf /etc/i2pd/tunnels.conf && chmod +x /entry.sh && mkdir -p /var/lib/i2pd /mirrors
ENTRYPOINT ["/entry.sh"]
