# =============================================================================
# PHP 8.5 (latest stable) — Alpine (menor superfície de ataque)
# Target: desenvolvimento local + paridade com produção
# =============================================================================

FROM php:8.5-fpm-alpine

# --- Metadados ---
LABEL maintainer="borba-jr"
LABEL description="Platform API — PHP 8.5 FPM + SQLite/MySQL ready"

# --- Dependências de sistema + extensões PHP ---
# apk add: somente o necessário para as extensões abaixo
# --no-cache: não deixa cache de índice na imagem final
RUN apk add --no-cache \
        sqlite \
        sqlite-dev \
        libzip-dev \
        oniguruma-dev \
        libxml2-dev \
        curl \
        # para php -S em scripts de debug se necessário
        busybox-extras \
    && docker-php-ext-install \
        pdo \
        pdo_sqlite \
        # MySQL pronto — só habilitar na hora que precisar
        pdo_mysql \
        mbstring \
        xml \
        zip \
    && rm -rf /var/cache/apk/*

# --- Configuração PHP para desenvolvimento ---
# Usa o template development já incluso no PHP oficial
RUN cp "$PHP_INI_DIR/php.ini-development" "$PHP_INI_DIR/php.ini"

# Overrides específicos do projeto
COPY php-dev.ini "$PHP_INI_DIR/conf.d/99-platform.ini"

# --- Usuário sem privilégio (não rodar como root) ---
# www-data já existe no Alpine; uid/gid 82
RUN chown -R www-data:www-data /var/www

# --- Diretório de trabalho ---
WORKDIR /var/www/html

# --- Porta FPM ---
EXPOSE 9000

USER www-data

CMD ["php-fpm"]
