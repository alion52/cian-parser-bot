# CIAN Parser Bot

Telegram-бот для парсинга объявлений ЦИАН с генерацией PDF-отчётов и ИИ-оценкой через OpenAI.

## Возможности

- Парсинг объявлений с ЦИАН
- Кеширование результатов (файлы + БД)
- Генерация PDF-отчётов
- ИИ-оценка объектов через GPT-4o-mini
- Память диалога
- Режимы пользователя
- Админ-панель на Laravel

## Стек

PHP 8+, Telegram Bot API, OpenAI API, MySQL, Guzzle, TCPDF, Laravel.

## Установка

```bash
git clone https://github.com/alion52/cian-parser-bot.git
cd cian-parser-bot
composer install
cp .env.example .env
cp config.example.php config.php
