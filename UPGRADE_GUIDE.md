# TalkTherapy Upgrade Guide: Laravel 11 → 12, Vue 3.4 → 3.5+

## Summary of Changes Made

### composer.json
- **Laravel Framework**: `^11.0` → `^12.0`
- **Inertia Laravel**: `^1.0` → `^2.0`
- **Laravel Breeze**: `^2.0` → `^2.3`
- **Laravel Reverb**: `^1.0.0-beta5` → `^1.0` (stable)
- **Laravel Tinker**: `^2.8` → `^2.9`
- **LaraDumps**: `^2.5` → `^3.0`
- **Pest**: `^2.0` → `^3.0`
- **Pest Plugin Laravel**: `^2.0` → `^3.0`
- **Spatie Ignition**: `^2.0` → `^3.0`
- **Laravel Sail**: `^1.18` → `^1.26`
- **Faker**: `^1.9.1` → `^1.23`
- **Mockery**: `^1.4.4` → `^1.6`
- **Collision**: `^8.1` → `^8.6`

### package.json
- **Vue**: `^3.4.0` → `^3.5.0`
- **Inertia Vue3**: `^1.0.0` → `^1.2.0`
- **Axios**: `^1.6.4` → `^1.7.0`
- **TailwindCSS**: `^3.2.1` → `^3.4.0`
- **Vite**: `^5.0.0` → `^6.0.0`

---

## Step-by-Step Installation (Run in order)

### 1. Backup Database
```bash
mysqldump -u root -p talktherapy_db > backup_$(date +%Y%m%d_%H%M%S).sql
```

### 2. Update PHP Dependencies
```bash
cd ~/Documents/Work/TalkTherapy
rm -rf vendor composer.lock
composer install --no-dev --optimize-autoloader
```

### 3. Update Node Dependencies
```bash
rm -rf node_modules package-lock.json
npm install
```

### 4. Laravel Configuration Updates
```bash
# Publish new Inertia middleware (needed for v2)
php artisan vendor:publish --provider="Inertia\ServiceProvider" --tag="middleware" --force

# Clear all caches
php artisan cache:clear
php artisan config:clear
php artisan view:clear
php artisan route:clear
php artisan optimize:clear
```

### 5. Inertia v2 Breaking Changes Review

#### Check for these patterns in your Vue files:

**Before (Inertia v1):**
```javascript
import { router } from '@inertiajs/vue3'
router.get('/url', data)
```

**After (Inertia v2):**
```javascript
import { router } from '@inertiajs/vue3'
router.visit('/url', { method: 'get', data: data })
```

**Scroll Behavior (new in v2):**
- Inertia v2 has improved scroll behavior
- Check any custom scroll handling in `resources/js/app.js`

### 6. Pest v3 Breaking Changes

```bash
# Run tests to check for issues
php artisan test
# or
./vendor/bin/pest
```

**Common Pest v3 changes:**
- Coverage configuration format may have changed
- Some assertion methods deprecated

### 7. Build Assets
```bash
npm run build
```

### 8. Verify Application
```bash
# Check PHP version (must be 8.2+)
php -v

# Run Laravel health checks
php artisan about

# Test routes
php artisan route:list --compact
```

---

## Potential Breaking Changes to Check

### Inertia v2
1. **Middleware**: New Inertia middleware published - check `app/Http/Middleware/HandleInertiaRequests.php`
2. **Prefetch**: New prefetching feature - may need to disable if causing issues
3. **History encryption**: Now encrypted by default

### Laravel 12
1. **Environment encryption**: New `env:encrypt` behavior if used
2. **Migration squashing**: New features don't affect existing apps
3. **Tailwind 4 support**: Your project stays on Tailwind 3 (good - no breaking changes)

### Vite 6
1. **Module format**: ESM only - your `type: "module"` in package.json is correct
2. **Build output**: Check `vite.config.js` for any deprecated options

### Pest 3
1. **Configuration**: `pest.xml` or `pest.xml.dist` may need updates
2. **Coverage**: New coverage driver requirements

---

## Testing Checklist

- [ ] Homepage loads correctly
- [ ] User login/logout works
- [ ] User registration works
- [ ] Therapy creation/editing works
- [ ] Counsellor profile loads
- [ ] Real-time chat (Reverb) works
- [ ] Admin panel loads
- [ ] All tests pass: `./vendor/bin/pest`
- [ ] Build completes without errors: `npm run build`

---

## Rollback Plan

If issues occur:

```bash
# Restore backups
cp composer.json.backup composer.json
cp package.json.backup package.json

# Restore vendor and node_modules from your git or backup
# Or reinstall:
composer install
npm install

# Clear caches
php artisan optimize:clear
```

Backups created:
- `composer.json.backup`
- `package.json.backup`

---

## Additional Resources

- [Laravel 12 Release Notes](https://laravel.com/docs/12.x/releases)
- [Inertia v2 Upgrade Guide](https://inertiajs.com/upgrade-guide)
- [Pest v3 Upgrade](https://pestphp.com/docs/upgrade-guide)

---

*Generated: $(date)*
