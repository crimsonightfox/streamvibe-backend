FROM php:8.2-apache

RUN docker-php-ext-install mysqli

# Suppress ServerName warning
RUN echo "ServerName localhost" >> /etc/apache2/apache2.conf

# Install PHPMailer directly (no Composer needed)
RUN mkdir -p /var/www/html/PHPMailer/src && \
    curl -sL https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/PHPMailer.php -o /var/www/html/PHPMailer/src/PHPMailer.php && \
    curl -sL https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/SMTP.php -o /var/www/html/PHPMailer/src/SMTP.php && \
    curl -sL https://raw.githubusercontent.com/PHPMailer/PHPMailer/master/src/Exception.php -o /var/www/html/PHPMailer/src/Exception.php

COPY . /var/www/html/

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
