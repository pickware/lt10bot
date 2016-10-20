lt10bot
=======

A Telegram bot to make reservations at http://kantine.lt10.de/.

# Init the bot:

    curl -F "url=https://lt10bot.herokuapp.com/webhook" https://api.telegram.org/bot<YOURTOKEN>/setWebhook
  
# Environment variables

All of these are required:

* ```DATABASE_URL``` connection URL for postgres
* ```LT10_USER``` and ```LT10_PASSWORD``` login credentials for your lt10.de account
* ```SYMFONY_ENV``` set to "prod" when running on heroku
* ```TELEGRAM_BOT_TOKEN``` the bot secret token
* ```TELEGRAM_CHAT_ID``` the ID of the group chat where you want to use the bot

