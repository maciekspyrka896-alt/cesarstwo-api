FROM php:8.3-cli
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev \
    && docker-php-ext-install pdo_pgsql pgsql \
    && rm -rf /var/lib/apt/lists/*
WORKDIR /app
COPY . /app
ENV PORT=8080
EXPOSE 8080
RUN printf '#!/bin/sh\nexec php -S 0.0.0.0:${PORT:-8080} router.php\n' > /start.sh \
    && chmod +x /start.sh
CMD ["/start.sh"]
