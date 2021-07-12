FROM php:php:7.4.21
RUN docker-php-ext-install sockets
COPY *.php /usr/src/myapp/
WORKDIR /usr/src/myapp/
CMD [ "php", "./e2pv.php" ]
