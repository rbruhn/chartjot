<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\Direction;
use App\Enums\ExitReason;
use App\Enums\NotePhase;
use App\Enums\TradeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;

class TradeIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // Support multipart: the AddOn sends a JSON `trade` field + `screenshot_file`
        // (the exit image) and, optionally, `entry_screenshot_file` (#72).
        if ($this->has('trade') && is_string($this->input('trade'))) {
            $decoded = json_decode($this->input('trade'), true);
            if (is_array($decoded)) {
                $this->merge($decoded);
            }
        }
    }

    public function rules(): array
    {
        $directions  = array_column(Direction::cases(), 'value');
        $exitReasons = array_column(ExitReason::cases(), 'value');
        $tradeTypes  = array_column(TradeType::cases(), 'value');
        $notePhases  = array_column(NotePhase::cases(), 'value');

        return [
            // Top-level identity
            'schema_version'   => ['required', 'integer', 'in:1'],
            'source'           => ['required', 'string', 'max:64'],
            'addon_version'    => ['required', 'string', 'max:32'],
            'trade_id'         => ['required', 'string', 'max:128'],
            'account_name'     => ['required', 'string', 'max:128'],
            'connection'       => ['required', 'string', 'max:128'],
            'trade_type'       => ['required', Rule::in($tradeTypes)],
            'trade_type_other' => ['nullable', 'string', 'max:64'],

            // #79: a copier follower names its master's trade_id; null for a master or a normal trade.
            'copier_master_trade_id' => ['nullable', 'string', 'max:255'],

            // Instrument
            'instrument'              => ['required', 'array'],
            'instrument.symbol'       => ['required', 'string', 'max:16'],
            'instrument.contract'     => ['required', 'string', 'max:32'],
            'instrument.tick_size'    => ['required', 'numeric', 'gt:0'],
            'instrument.point_value'  => ['required', 'numeric', 'gt:0'],

            // Direction and quantity
            'direction'            => ['required', Rule::in($directions)],
            'quantity'             => ['required', 'integer', 'min:1'],
            'total_entry_quantity' => ['required', 'integer', 'min:1'],

            // Entry / exit
            'entry'               => ['required', 'array'],
            'entry.occurred_at'   => ['required', 'date'],
            'entry.average_price' => ['required', 'numeric', 'gt:0'],
            'entry.order_name'    => ['nullable', 'string', 'max:64'],
            'exit'                => ['required', 'array'],
            'exit.occurred_at'    => ['required', 'date', 'after_or_equal:entry.occurred_at'],
            'exit.average_price'  => ['required', 'numeric', 'gt:0'],
            'exit.order_name'     => ['nullable', 'string', 'max:64'],
            'exit.reason'         => ['required', Rule::in($exitReasons)],

            // Optional stop price; never required, so older AddOns still post.
            'stop_price'          => ['nullable', 'numeric', 'gt:0'],

            // Performance
            'performance'              => ['required', 'array'],
            'performance.points'       => ['required', 'numeric'],
            'performance.ticks'        => ['required', 'integer'],
            'performance.gross_pnl'    => ['required', 'numeric'],
            'performance.commission'   => ['required', 'numeric', 'min:0'],
            'performance.fees'         => ['nullable', 'numeric', 'min:0'],
            'performance.net_pnl'      => ['required', 'numeric'],

            // Excursion
            'excursion'                         => ['required', 'array'],
            'excursion.mae_points'              => ['nullable', 'numeric'],
            'excursion.mfe_points'              => ['nullable', 'numeric'],
            'excursion.max_adverse_price'       => ['nullable', 'numeric'],
            'excursion.max_favorable_price'     => ['nullable', 'numeric'],
            'excursion.complete'                => ['required', 'boolean'],

            // Legs
            'legs'                       => ['nullable', 'array'],
            'legs.*.sequence'            => ['required', 'integer', 'min:1'],
            'legs.*.runner'              => ['required', 'boolean'],
            'legs.*.exit_order_id'       => ['nullable', 'string', 'max:128'],
            'legs.*.order_name'          => ['nullable', 'string', 'max:64'],
            'legs.*.reason'              => ['required', Rule::in($exitReasons)],
            'legs.*.quantity'            => ['required', 'integer', 'min:1'],
            'legs.*.exited_at'           => ['required', 'date'],
            'legs.*.average_exit_price'  => ['required', 'numeric', 'gt:0'],
            'legs.*.points'              => ['required', 'numeric'],
            'legs.*.gross_pnl'           => ['required', 'numeric'],
            'legs.*.mae_points'          => ['nullable', 'numeric'],
            'legs.*.mfe_points'          => ['nullable', 'numeric'],

            // Executions
            'executions'                       => ['required', 'array', 'min:1'],
            'executions.*.execution_id'        => ['required', 'string', 'max:128'],
            'executions.*.order_id'            => ['nullable', 'string', 'max:128'],
            'executions.*.occurred_at'         => ['required', 'date'],
            'executions.*.action'              => ['required', 'in:buy,sell'],
            'executions.*.role'                => ['required', 'in:entry,exit'],
            'executions.*.quantity'            => ['required', 'integer', 'min:1'],
            'executions.*.allocated_quantity'  => ['required', 'integer', 'min:1'],
            'executions.*.price'               => ['required', 'numeric', 'gt:0'],
            'executions.*.commission'          => ['nullable', 'numeric', 'min:0'],
            'executions.*.fee'                 => ['nullable', 'numeric', 'min:0'],
            'executions.*.order_name'          => ['nullable', 'string', 'max:64'],
            'executions.*.position_after'      => ['required', 'integer'],

            // Notes
            'notes'               => ['nullable', 'array'],
            'notes.*.body'        => ['required', 'string', 'max:10000'],
            'notes.*.phase'       => ['required', Rule::in($notePhases)],
            'notes.*.occurred_at' => ['required', 'date'],

            // Screenshot metadata
            'screenshot'              => ['nullable', 'array'],
            'screenshot.captured_at'  => ['nullable', 'date'],
            'screenshot.caption'      => ['nullable', 'string', 'max:255'],

            // Screenshot file (multipart)
            'screenshot_file' => ['nullable', 'file', 'mimes:png,jpeg,jpg', 'max:10240'],

            // Entry image (#72): captured when the trade opened; optional, like the exit image.
            'entry_screenshot'             => ['nullable', 'array'],
            'entry_screenshot.captured_at' => ['nullable', 'date'],
            'entry_screenshot_file'        => ['nullable', 'file', 'mimes:png,jpeg,jpg', 'max:10240'],
        ];
    }
}
