# Contributing to Custom XML Sitemap

## Development Setup

### Prerequisites

- PHP 8.4+
- Node.js 20+
- pnpm 9+
- Composer 2+
- Docker (for wp-env)

### Installation

```bash
# Clone the repository
git clone git@github.com:xwp/custom-xml-sitemap.git
cd custom-xml-sitemap

# Install dependencies
composer install
pnpm install

# Build assets
pnpm run build

# Start local environment
pnpm run env:start
```

### Local Development

```bash
# Start wp-env Docker environment
pnpm run env:start

# Watch for JS changes
pnpm run start

# Run PHP linting
composer lint

# Run PHPStan static analysis
composer phpstan

# Run PHPUnit tests
pnpm run test:php

# Generate translation POT file
pnpm run i18n:pot
```

### Admin Access

- URL: http://localhost:8888/wp-admin/
- Username: `admin`
- Password: `password`

## Code Standards

- Follow [WordPress VIP coding standards](https://docs.wpvip.com/technical-references/code-quality/)
- PHP code must pass PHPCS (`composer lint`)
- PHP code must pass PHPStan level 8 (`composer phpstan`)
- All tests must pass (`pnpm run test:php`)

## Pull Request Process

1. Create a feature branch from `main`
2. Make your changes
3. Ensure all checks pass:
   ```bash
   composer lint
   composer phpstan
   pnpm run test:php
   ```
4. Submit a pull request to `main`

## Release Process

Releases are automated via GitHub Actions. The workflow builds JS assets and creates a tagged release that Packagist can use.

### Steps to Release

1. **Update the version** in `custom-xml-sitemap.php`:
   ```php
   * Version: 1.0.1
   ```

2. **Commit the version bump** to `main`:
   ```bash
   git add custom-xml-sitemap.php
   git commit -m "Bump version to 1.0.1"
   git push origin main
   ```

3. **Push to the release branch** to trigger the workflow:
   ```bash
   git push origin main:release
   ```

4. **GitHub Actions will automatically**:
   - Build JS assets (`pnpm run build`)
   - Create a release branch with built assets committed
   - Create a version tag (e.g., `1.0.1`)
   - Create a GitHub Release with downloadable zip
   - Packagist updates automatically via webhook

### What Gets Released

The release tag includes:
- `assets/build/` - Compiled JavaScript (built during release)
- `assets/xsl/` - XSL stylesheets
- `languages/` - Translation files
- `src/` - PHP source code
- `templates/` - PHP templates
- `custom-xml-sitemap.php` - Main plugin file
- `README.md` and `readme.txt`

Excluded from release (via `.gitattributes`):
- `assets/src/` - Source JavaScript
- `tests/` - Test files
- `.github/` - GitHub workflows
- Development config files

### Composer/Packagist

Users install via:
```bash
composer require xwp/custom-xml-sitemap
```

Composer automatically installs the Action Scheduler dependency.

## License

By contributing, you agree that your contributions will be licensed under the GPL-2.0-or-later license.
