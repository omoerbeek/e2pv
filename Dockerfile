FROM php:latest
RUN docker-php-ext-install sockets
COPY *.php /usr/src/myapp/
WORKDIR /usr/src/myapp/
CMD [ "php", "./e2pv.php" ]