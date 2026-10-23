<?php

namespace Okay\Modules\Sviat\ReviewRequests\Init;

use DateTimeImmutable;
use Okay\Core\Config;
use Okay\Core\EntityFactory;
use Okay\Core\Modules\AbstractInit;
use Okay\Core\Modules\EntityField;
use Okay\Core\Notify;
use Okay\Core\QueryFactory;
use Okay\Core\Scheduler\Schedule;
use Okay\Core\ServiceLocator;
use Okay\Core\Settings;
use Okay\Helpers\CommentsHelper;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestItemsEntity;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestOptoutsEntity;
use Okay\Modules\Sviat\ReviewRequests\Entities\ReviewRequestsEntity;
use Okay\Modules\Sviat\ReviewRequests\Extenders\FrontExtender;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestCron;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestQueue;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestRules;
use Okay\Modules\Sviat\ReviewRequests\Helpers\ReviewRequestSettings;

class Init extends AbstractInit
{
    public const PERMISSION = 'sviat__review_requests';

    public function install()
    {
        $this->setBackendMainController('ReviewRequestsAdmin');

        // Видалення модуля в адмінці таблиць не прибирає, а ядро створює таблицю
        // без IF NOT EXISTS: при повторному встановленні в лозі була б SQL-помилка.
        $existing = $this->existingTables();

        if (!isset($existing[ReviewRequestsEntity::getTable()])) {
            $this->migrateEntityTable(ReviewRequestsEntity::class, [
                (new EntityField('id'))->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                (new EntityField('order_id'))->setTypeInt(11)->setIndexUnique(),
                (new EntityField('status'))->setTypeEnum(ReviewRequestsEntity::STATUSES, false)->setIndex(),
                (new EntityField('skip_reason'))->setTypeVarchar(32)->setNullable(),
                (new EntityField('email_hash'))->setTypeVarchar(64)->setNullable()->setIndex(),
                (new EntityField('lang_id'))->setTypeInt(11)->setNullable(),
                (new EntityField('detected_at'))->setTypeDatetime()->setNullable()->setIndex(),
                (new EntityField('sent_at'))->setTypeDatetime()->setNullable()->setIndex(),
                (new EntityField('token_hash'))->setTypeVarchar(64)->setNullable()->setIndexUnique(),
                (new EntityField('unsubscribe_hash'))->setTypeVarchar(64)->setNullable()->setIndexUnique(),
                (new EntityField('token_expires_at'))->setTypeDatetime()->setNullable(),
                (new EntityField('first_visit_at'))->setTypeDatetime()->setNullable(),
                (new EntityField('attempts'))->setTypeTinyInt(3)->setDefault(0),
                (new EntityField('retry_at'))->setTypeDatetime()->setNullable(),
                (new EntityField('error_text'))->setTypeText()->setNullable(),
            ]);
        }

        if (!isset($existing[ReviewRequestItemsEntity::getTable()])) {
            $this->migrateEntityTable(ReviewRequestItemsEntity::class, [
                (new EntityField('id'))->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                (new EntityField('request_id'))->setTypeInt(11)->setIndex(),
                (new EntityField('purchase_id'))->setTypeInt(11),
                (new EntityField('product_id'))->setTypeInt(11)->setIndex(),
                (new EntityField('comment_id'))->setTypeInt(11)->setNullable()->setIndexUnique(),
                (new EntityField('reviewed_at'))->setTypeDatetime()->setNullable(),
            ]);
        }

        if (!isset($existing[ReviewRequestOptoutsEntity::getTable()])) {
            $this->migrateEntityTable(ReviewRequestOptoutsEntity::class, [
                (new EntityField('id'))->setIndexPrimaryKey()->setTypeInt(11, false)->setAutoIncrement(),
                (new EntityField('email_hash'))->setTypeVarchar(64)->setIndexUnique(),
                (new EntityField('user_id'))->setTypeInt(11)->setNullable(),
                (new EntityField('created_at'))->setTypeDatetime()->setNullable(),
            ]);
        }

        // Таблиці з попереднього встановлення можуть не мати пізніших колонок,
        // а Entity вибирає всі поля явно: без колонки падав би кожен запит до таблиці.
        $this->migrateEntityField(ReviewRequestsEntity::class, (new EntityField('retry_at'))->setTypeDatetime()->setNullable());
        $this->migrateEntityField(ReviewRequestsEntity::class, (new EntityField('unsubscribe_hash'))->setTypeVarchar(64)->setNullable()->setIndexUnique());
        $this->migrateEntityField(ReviewRequestOptoutsEntity::class, (new EntityField('user_id'))->setTypeInt(11)->setNullable());

        // Тестовий режим і базова лінія — разом: модуль, щойно встановлений,
        // не може надіслати жодного листа, а історія замовлень не стане чергою.
        // Сервіси модуля під час install() ще не зареєстровані, тож збираємо руками.
        $serviceLocator = ServiceLocator::getInstance();
        $settings = new ReviewRequestSettings($serviceLocator->getService(Settings::class));
        $settings->set('mode', ReviewRequestSettings::MODE_TEST);
        $settings->set('installed_at', (new DateTimeImmutable())->format('Y-m-d H:i:s'));

        (new ReviewRequestQueue(
            $serviceLocator->getService(EntityFactory::class),
            $serviceLocator->getService(QueryFactory::class),
            $settings,
            new ReviewRequestRules($settings)
        ))->baseline(new DateTimeImmutable());
    }

    /**
     * Замовлення без email більше не потрапляють у журнал (ReviewRequestQueue::HAS_EMAIL),
     * тож прибираємо вже записані — лише ті, що пропущені при виявленні: у них
     * немає хеша адреси й жодної спроби. Пропуск при відправці, коли адресу
     * стерли вже після постановки в чергу, зберігає хеш (ReviewRequestCron) і
     * лишається в журналі разом з історією спроб.
     */
    public function update_1_0_3(): void
    {
        ServiceLocator::getInstance()->getService(QueryFactory::class)->newSqlQuery()->setStatement(
            'DELETE FROM __sviat__review_requests
             WHERE status = :skipped AND skip_reason = :reason
               AND email_hash IS NULL AND attempts = 0'
        )->bindValues([
            'skipped' => ReviewRequestsEntity::STATUS_SKIPPED,
            'reason' => 'no_email',
        ])->execute();
    }

    /** @return array<string, true> таблиці БД у вигляді Entity::getTable(), тобто з «__» замість префікса */
    private function existingTables(): array
    {
        $serviceLocator = ServiceLocator::getInstance();
        $prefix = (string) $serviceLocator->getService(Config::class)->get('db_prefix');
        $tables = [];
        foreach ($serviceLocator->getService(QueryFactory::class)->newSqlQuery()->setStatement('SHOW TABLES')->results() as $row) {
            $name = (string) current((array) $row);
            if (str_starts_with($name, $prefix)) {
                $tables['__' . substr($name, strlen($prefix))] = true;
            }
        }

        return $tables;
    }

    public function init()
    {
        $this->addPermission(self::PERMISSION);
        $this->registerBackendController('ReviewRequestsAdmin');
        $this->addBackendControllerPermission('ReviewRequestsAdmin', self::PERMISSION);
        $this->extendBackendMenu('left_comments', [
            'sviat__review_requests__menu' => ['ReviewRequestsAdmin'],
        ]);

        $this->registerChainExtension(
            [CommentsHelper::class, 'getList'],
            [FrontExtender::class, 'markVerifiedPurchases']
        );
        $this->registerChainExtension(
            [Notify::class, 'SMTP'],
            [FrontExtender::class, 'addUnsubscribeHeader']
        );

        $this->registerSchedule(
            (new Schedule([ReviewRequestCron::class, 'run']))
                ->name('ReviewRequests: виявити виконані замовлення й надіслати прохання про відгук')
                ->time('*/15 * * * *')
                ->overlap(false)
                ->timeout(600)
        );
    }
}
