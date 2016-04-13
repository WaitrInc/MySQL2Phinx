<?php
use Phinx\Migration\AbstractMigration;
use Phinx\Db\Adapter\MysqlAdapter;

class InitialMigration extends AbstractMigration
{
    public function up()
    {
        // Automatically created phinx migration commands for tables from database payments

        // Migration for table disbursements
        $table = $this->table('disbursements');
        $table
            ->addColumn('disbursement_id', 'string', array('limit' => 255))
            ->addColumn('merchant_id', 'string', array('limit' => 255))
            ->addColumn('amount', 'decimal', array())
            ->addColumn('disbursement_date', 'timestamp', array())
            ->addColumn('added_date', 'timestamp', array('default' => 'CURRENT_TIMESTAMP'))
            ->addColumn('last_updated_date', 'timestamp', array('default' => 'CURRENT_TIMESTAMP'))
            ->create();


        // Migration for table merchants
        $table = $this->table('merchants');
        $table
            ->addColumn('merchant_id', 'string', array('limit' => 255))
            ->addColumn('flat_fee', 'decimal', array('default' => '0.55'))
            ->addColumn('percent_fee', 'decimal', array('default' => '0.0350'))
            ->addColumn('should_put_in_escrow', 'integer', array('limit' => MysqlAdapter::INT_TINY))
            ->addColumn('disbursement_period', 'enum', array('values' => array('daily','weekly','monthly')))
            ->addColumn('added_date', 'timestamp', array('default' => 'CURRENT_TIMESTAMP'))
            ->addColumn('last_updated_date', 'timestamp', array('default' => 'CURRENT_TIMESTAMP'))
            ->create();


        // Migration for table refunds
        $table = $this->table('refunds');
        $table
            ->addColumn('original_transaction_id', 'string', array('limit' => 255))
            ->addColumn('amount', 'decimal', array())
            ->addColumn('status', 'enum', array('values' => array('waiting_for_submission','waiting_for_settlement','finished')))
            ->addColumn('reason', 'text', array())
            ->addColumn('type', 'enum', array('values' => array('waitr','restaurant','1','2')))
            ->addColumn('added_date', 'timestamp', array('default' => 'CURRENT_TIMESTAMP'))
            ->addColumn('last_updated_date', 'timestamp', array('default' => 'CURRENT_TIMESTAMP'))
            ->create();


        // Migration for table transactions
        $table = $this->table('transactions');
        $table
            ->addColumn('transaction_id', 'string', array('limit' => 255))
            ->addColumn('merchant_id', 'string', array('limit' => 255))
            ->addColumn('customer_id', 'string', array('null' => true, 'limit' => 255))
            ->addColumn('disbursement_id', 'string', array('null' => true, 'limit' => 255))
            ->addColumn('amount', 'decimal', array())
            ->addColumn('waitr_fee', 'decimal', array())
            ->addColumn('status', 'enum', array('values' => array('voided','waiting_for_submission','submitted_for_settlement','disbursed')))
            ->addColumn('added_date', 'timestamp', array('default' => 'CURRENT_TIMESTAMP'))
            ->addColumn('submitted_date', 'timestamp', array('null' => true, 'default' => 'CURRENT_TIMESTAMP'))
            ->addColumn('settled_date', 'timestamp', array('null' => true))
            ->addColumn('escrowed_date', 'timestamp', array('null' => true))
            ->addColumn('disbursed_date', 'timestamp', array('null' => true))
            ->addColumn('last_updated_date', 'timestamp', array('default' => 'CURRENT_TIMESTAMP'))
            ->create();


        // Migration for table upcharges
        $table = $this->table('upcharges');
        $table
            ->addColumn('transaction_id', 'string', array('limit' => 255))
            ->addColumn('original_transaction_id', 'string', array('limit' => 255))
            ->addColumn('amount', 'decimal', array())
            ->addColumn('waitr_fee', 'decimal', array())
            ->addColumn('status', 'enum', array('values' => array('waiting_for_settlement_submission','status_submitted_for_settlement','voided')))
            ->addColumn('reason', 'text', array())
            ->addColumn('type', 'enum', array('values' => array('waitr','restaurant','1','2')))
            ->addColumn('added_date', 'timestamp', array('default' => 'CURRENT_TIMESTAMP'))
            ->addColumn('last_updated_date', 'timestamp', array('default' => 'CURRENT_TIMESTAMP'))
            ->create();


    }
}
