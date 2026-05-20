# Local osTicket test image.
# Pulls osTicket 1.18.3 release zip and wires it up under Apache + PHP 8.1.
FROM php:8.1-apache

# osTicket runtime requirements.
RUN apt-get update \
 && apt-get install -y --no-install-recommends \
        unzip ca-certificates curl libicu-dev libonig-dev libxml2-dev \
        libzip-dev libpng-dev libfreetype6-dev libjpeg-dev libgmp-dev \
        libldb-dev libldap2-dev \
 && docker-php-ext-configure gd --with-freetype --with-jpeg \
 && docker-php-ext-install -j$(nproc) \
        mysqli intl mbstring xml zip gd gmp opcache \
 && a2enmod rewrite \
 && rm -rf /var/lib/apt/lists/*

# Reasonable PHP defaults for osTicket.
RUN { \
      echo 'memory_limit=256M'; \
      echo 'post_max_size=64M'; \
      echo 'upload_max_filesize=64M'; \
      echo 'max_execution_time=120'; \
      echo 'date.timezone=America/Mexico_City'; \
    } > /usr/local/etc/php/conf.d/osticket.ini

ENV OSTICKET_VERSION=v1.18.3
WORKDIR /var/www/html

# Download + extract osTicket.
RUN curl -fsSL -o /tmp/osticket.zip \
      "https://github.com/osTicket/osTicket/releases/download/${OSTICKET_VERSION}/osTicket-${OSTICKET_VERSION}.zip" \
 && unzip -q /tmp/osticket.zip -d /tmp/osticket \
 && cp -r /tmp/osticket/upload/. /var/www/html/ \
 && rm -rf /tmp/osticket /tmp/osticket.zip \
 && cp /var/www/html/include/ost-sampleconfig.php /var/www/html/include/ost-config.php \
 && chown -R www-data:www-data /var/www/html

EXPOSE 80
