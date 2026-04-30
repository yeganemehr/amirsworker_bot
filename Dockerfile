FROM dunglas/frankenphp:1.12.2-php8.4-alpine

RUN --mount=type=bind,source=.docker/fs,target=/mnt \
    apk add --no-cache supervisor && \
    curl -s https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin/ --filename=composer && \
    cp -Rv /mnt/* /

COPY --exclude=./docker --exclude=./Dockerfile . /app

RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    composer i --no-cache --optimize-autoloader --no-dev

CMD ["/bin/start"]
