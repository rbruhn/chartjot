<?php

namespace App\Http\Requests\Api\V1;

use App\Enums\CopiesSource;
use App\Enums\CopyStatus;
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
        // Support multipart: the AddOn sends a JSON `trade` field + `screenshot_file`.
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
        $copyStatuses = array_column(CopyStatus::cases(), 'value');
        $copiesSources = array_column(CopiesSource::cases(), 'value');

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

            // Instrument
            'instrument'              => ['required', 'array'],
            'instrument.symbol'       => ['required', 'string', 'max:16'],
            'instrument.contract'     => ['required', 'string', 'max:32'],
            'instrument.tick_size'    => ['required', 'numeric'],
            'instrument.point_value'  => ['required', 'numeric'],

            // Direction and quantity
            'direction'            => ['required', Rule::in($directions)],
            'quantity'             => ['required', 'integer', 'min:1'],
            'total_entry_quantity' => ['required', 'integer', 'min:1'],

            // Entry / exit
            'entry'               => ['required', 'array'],
            'entry.occurred_at'   => ['required', 'date'],
            'entry.average_price' => ['required', 'numeric'],
            'entry.order_name'    => ['nullable', 'string', 'max:64'],
            'exit'                => ['required', 'array'],
            'exit.occurred_at'    => ['required', 'date', 'after_or_equal:entry.occurred_at'],
            'exit.average_price'  => ['required', 'numeric'],
            'exit.order_name'     => ['nullable', 'string', 'max:64'],
            'exit.reason'         => ['required', Rule::in($exitReasons)],

            // Performance
            'performance'              => ['required', 'array'],
            'performance.points'       => ['required', 'numeric'],
            'performance.ticks'        => ['required', 'integer'],
            'performance.gross_pnl'    => ['required', 'numeric'],
            'performance.commission'   => ['required', 'numeric'],
            'performance.fees'         => ['nullable', 'numeric'],
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
            'legs.*.average_exit_price'  => ['required', 'numeric'],
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
            'executions.*.price'               => ['required', 'numeric'],
            'executions.*.commission'          => ['nullable', 'numeric'],
            'executions.*.fee'                 => ['nullable', 'numeric'],
            'executions.*.order_name'          => ['nullable', 'string', 'max:64'],
            'executions.*.position_after'      => ['required', 'integer'],

            // Notes
            'notes'               => ['nullable', 'array'],
            'notes.*.body'        => ['required', 'string'],
            'notes.*.phase'       => ['required', Rule::in($notePhases)],
            'notes.*.occurred_at' => ['required', 'date'],

            // Screenshot metadata
            'screenshot'              => ['nullable', 'array'],
            'screenshot.captured_at'  => ['nullable', 'date'],
            'screenshot.caption'      => ['nullable', 'string', 'max:255'],

            // Screenshot file (multipart)
            'screenshot_file' => ['nullable', 'file', 'mimes:png,jpeg,jpg', 'max:10240'],

            // Copies
            'copies_source'   => ['nullable', Rule::in($copiesSources)],
            'copies_summary'  => ['nullable', 'array'],
            'copies'          => ['nullable', 'array'],

            'copies.*.account_name'              => ['required', 'string', 'max:128'],
            'copies.*.status'                    => ['required', Rule::in($copyStatuses)],
            'copies.*.instrument'                => ['nullable', 'array'],
            'copies.*.instrument.symbol'         => ['nullable', 'string', 'max:16'],
            'copies.*.instrument.contract'       => ['nullable', 'string', 'max:32'],
            'copies.*.instrument.tick_size'      => ['nullable', 'numeric'],
            'copies.*.instrument.point_value'    => ['nullable', 'numeric'],
            'copies.*.expected'                  => ['nullable', 'array'],
            'copies.*.expected.contract_size'    => ['nullable', 'string'],
            'copies.*.expected.multiplier'       => ['nullable', 'numeric'],
            'copies.*.expected.faded'            => ['nullable', 'boolean'],
            'copies.*.expected.blown'            => ['nullable', 'boolean'],
            'copies.*.expected.quantity'         => ['nullable', 'integer'],
            'copies.*.warnings'                  => ['nullable', 'array'],
            'copies.*.direction'                 => ['nullable', Rule::in($directions)],
            'copies.*.quantity'                  => ['nullable', 'integer'],
            'copies.*.entry_average_price'       => ['nullable', 'numeric'],
            'copies.*.exit_average_price'        => ['nullable', 'numeric'],
            'copies.*.entered_at'                => ['nullable', 'date'],
            'copies.*.exited_at'                 => ['nullable', 'date'],
            'copies.*.performance'               => ['nullable', 'array'],
            'copies.*.performance.points'        => ['nullable', 'numeric'],
            'copies.*.performance.ticks'         => ['nullable', 'integer'],
            'copies.*.performance.gross_pnl'     => ['nullable', 'numeric'],
            'copies.*.performance.commission'    => ['nullable', 'numeric'],
            'copies.*.performance.fees'          => ['nullable', 'numeric'],
            'copies.*.performance.net_pnl'       => ['nullable', 'numeric'],
            'copies.*.executions'                => ['nullable', 'array'],
            'copies.*.executions.*.execution_id'       => ['required', 'string', 'max:128'],
            'copies.*.executions.*.order_id'           => ['nullable', 'string', 'max:128'],
            'copies.*.executions.*.occurred_at'        => ['required', 'date'],
            'copies.*.executions.*.action'             => ['required', 'in:buy,sell'],
            'copies.*.executions.*.role'               => ['required', 'in:entry,exit'],
            'copies.*.executions.*.quantity'           => ['required', 'integer', 'min:1'],
            'copies.*.executions.*.allocated_quantity' => ['required', 'integer', 'min:1'],
            'copies.*.executions.*.price'              => ['required', 'numeric'],
            'copies.*.executions.*.commission'         => ['nullable', 'numeric'],
            'copies.*.executions.*.fee'                => ['nullable', 'numeric'],
            'copies.*.executions.*.order_name'         => ['nullable', 'string', 'max:64'],
            'copies.*.executions.*.position_after'     => ['required', 'integer'],
        ];
    }
}
