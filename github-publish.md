# Публикация OmniRoute Health Monitor на GitHub

## Описание
Автоматизированная система мониторинга состояния сервиса OmniRoute с функциями глубокой диагностики и авто-перезапуска.

## Структура репозитория
- monitor_omniroute.php - основной скрипт мониторинга.
- start_omniroute.sh - скрипт запуска сервиса.
- project.md - документация проекта.
- README.md - инструкция по развертыванию.

## Инструкция по установке
1. Клонируйте репозиторий: git clone <URL>
2. Установите права: chmod +x monitor_omniroute.php start_omniroute.sh
3. Настройте crontab для автоматического запуска:
   * * * * * /usr/bin/php /путь/к/мониторингу/monitor_omniroute.php >> /путь/к/мониторингу/monitor.log 2>&1

## Требования
- PHP 7.4+
- systemd (user session)
- Права на запись в директории логов и состояний.
