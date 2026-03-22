# Use the official PHP image as a base
FROM php:8.4-cli

# Install dependencies required for PHP extensions and Clang
RUN apt-get update && apt-get install -y \
    libffi-dev \
    clang-19 \
    git \
    wget \
    unzip \
    lsb-release \
    && rm -rf /var/lib/apt/lists/*

# Set Clang 19 as the default clang version
RUN update-alternatives --install /usr/bin/clang clang /usr/bin/clang-19 100 \
    && update-alternatives --install /usr/bin/clang++ clang++ /usr/bin/clang++-19 100

# Enable FFI extension
RUN docker-php-ext-install ffi

# Install the pcov extension using PECL
RUN pecl install pcov \
    && docker-php-ext-enable pcov

# Install Composer globally
RUN curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
RUN composer --version

# Clean up temporary files
RUN apt-get clean

# Set the working directory
WORKDIR /home

# Set the default command to run PHP
CMD ["composer", "run-script", "check"]
