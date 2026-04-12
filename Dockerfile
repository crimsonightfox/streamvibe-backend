FROM php:8.2-apache

RUN docker-php-ext-install mysqli

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

COPY . /var/www/html/

# Install PHPMailer via Composer
WORKDIR /var/www/html
RUN composer require phpmailer/phpmailer

RUN chown -R www-data:www-data /var/www/html

EXPOSE 80
