# XPLENDOR

Esse comando é usado para agregar views e leads de todos os carros todos os dias às 00:30.

### Como executar

```bash
docker exec -it php php artisan performance:aggregate --from=2026-03-04 --to=2026-03-13 --sync
```