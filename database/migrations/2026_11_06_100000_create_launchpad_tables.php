<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * TPIX Launchpad — child-token sales paid with USDT on BSC (2026-11-06)
 *
 *   launchpad_sales      one row per LaunchpadFactory launch (read from chain, approved by an admin)
 *   launchpad_orders     a buyer's signed intent: "my next deposit of exactly X USDT from this wallet buys this sale"
 *   launchpad_agent_txs  every transaction the sale-agent wallet signs — saved BEFORE broadcast (never sign twice)
 *   bridge_deposits      + mint_to / launchpad_order_id: a deposit matched to an order mints to the sale agent
 *
 * Business times are DATETIME on purpose: prod MariaDB gives the first TIMESTAMP NOT NULL column of a table
 * ON UPDATE CURRENT_TIMESTAMP on its own (see 2026_10_30_230000_stop_business_times_auto_updating).
 * On-chain amounts are decimal strings (uint256 does not fit any integer column).
 */
return new class() extends Migration
{
    public function up(): void
    {
        Schema::create('launchpad_sales', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('chain_id')->default(4289);
            $table->string('factory_address', 42);
            $table->unsignedBigInteger('launch_id');
            $table->string('sale_address', 42)->unique();
            $table->string('feeder_address', 42);
            $table->string('vesting_address', 42)->nullable();
            $table->string('token_address', 42)->index();
            $table->string('token_name', 120);
            $table->string('token_symbol', 32)->index();
            $table->unsignedTinyInteger('token_decimals')->default(18);
            $table->string('quote_address', 42);
            $table->string('rate_numerator', 80);
            $table->string('rate_denominator', 80);
            $table->string('sale_cap', 80);
            $table->string('min_feed', 80)->default('1');
            $table->unsignedSmallInteger('pool_share_bps');
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('lp_unlock_at');
            $table->string('creator_address', 42)->index();
            $table->boolean('accepts_bsc')->default(true);
            $table->string('status', 20)->default('pending')->index(); // pending | approved | rejected | hidden
            $table->string('status_note', 300)->nullable();
            $table->string('reviewed_by', 80)->nullable();
            $table->dateTime('reviewed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('launchpad_orders', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('launchpad_sale_id')->constrained('launchpad_sales');
            $table->string('buyer_address', 42)->index();
            $table->unsignedInteger('source_chain_id');
            $table->string('amount_units', 40);      // exact USDT the buyer must send (6 decimals)
            $table->string('pay_units', 40);         // USDT_TPIX the agent spends = amount - bridge deposit fee
            $table->string('expected_tokens', 80);   // quote(pay_units) when the order was made
            $table->string('status', 24)->index();
            $table->dateTime('expires_at');
            $table->dateTime('risk_accepted_at');
            $table->string('message_hash', 66)->unique(); // keccak of the signed text — one order per signature
            $table->text('signature');
            $table->string('client_ip', 45)->nullable();
            $table->unsignedBigInteger('bridge_deposit_id')->nullable()->index();
            $table->dateTime('paid_at')->nullable();
            $table->string('tokens_out', 80)->nullable();
            $table->string('delivery_tx_hash', 66)->nullable();
            $table->string('refund_tx_hash', 66)->nullable();
            $table->dateTime('settled_at')->nullable();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 300)->nullable();
            $table->timestamps();

            $table->index(['buyer_address', 'status']);
        });

        Schema::create('launchpad_agent_txs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 16);             // approve | buy | refund | feed
            $table->foreignId('launchpad_sale_id')->nullable()->constrained('launchpad_sales');
            $table->foreignId('launchpad_order_id')->nullable()->constrained('launchpad_orders');
            $table->string('to_address', 42);
            $table->text('calldata');
            $table->string('tx_hash', 66)->unique();
            $table->text('raw_tx');
            $table->unsignedBigInteger('nonce');
            $table->string('status', 16)->index();  // pending | confirmed | failed | stuck
            $table->dateTime('broadcast_at');
            $table->unsignedBigInteger('mined_block')->nullable();
            $table->string('last_error', 300)->nullable();
            $table->timestamps();
        });

        Schema::table('bridge_deposits', function (Blueprint $table) {
            // null = mint to the depositor (every deposit before the launchpad, and every unmatched one)
            $table->string('mint_to', 42)->nullable()->after('from_address');
            $table->unsignedBigInteger('launchpad_order_id')->nullable()->index()->after('mint_to');
        });
    }

    public function down(): void
    {
        Schema::table('bridge_deposits', function (Blueprint $table) {
            $table->dropIndex(['launchpad_order_id']);
            $table->dropColumn(['mint_to', 'launchpad_order_id']);
        });
        Schema::dropIfExists('launchpad_agent_txs');
        Schema::dropIfExists('launchpad_orders');
        Schema::dropIfExists('launchpad_sales');
    }
};
