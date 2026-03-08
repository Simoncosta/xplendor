# DEPLOY (08/03/2025)

- [ ] docker compose exec -it xplendor-php php artisan db:seed --class=CarBrandAndModelCitroenSeeder
- [ ] docker compose exec -it xplendor-php php artisan db:seed --class=CarBrandAndModelIvecoSeeder
- [ ] docker compose exec -it xplendor-php php artisan db:seed --class=CarBrandAndModelJaguarSeeder
- [ ] docker compose exec -it xplendor-php php artisan db:seed --class=CarBrandAndModelLexusSeeder
- [ ] docker compose exec -it xplendor-php php artisan migrate

# DEPLOY (05/03/2025)

- [x] docker compose exec -it xplendor-php php artisan migrate
