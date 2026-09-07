-- Sail convention: a second database for phpunit (DB_DATABASE=testing in phpunit.xml).
-- Shipped as .sql because the mysql:8.4 entrypoint pipes .sql files to mysql instead of exec-ing
-- them, which fails on macOS bind mounts ("bad interpreter: Permission denied").
CREATE DATABASE IF NOT EXISTS `testing`;
GRANT ALL PRIVILEGES ON `testing%`.* TO 'sail'@'%';
FLUSH PRIVILEGES;
