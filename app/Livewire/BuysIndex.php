<?php

namespace App\Livewire;

use App\Models\Buy;
use App\Services\MoneyService;
use Livewire\Component;
use Livewire\WithPagination;

class BuysIndex extends Component
{
    use WithPagination;

    public $search = '';
    protected $updatesQueryString = ['search'];

    public function updatingSearch()
    {
        $this->resetPage();
    }

    public function render()
    {
        $buys = Buy::with('purchaseItem')
            ->withSum('purchaseItem', 'total_price')
            ->where('title', 'like', '%' . $this->search . '%')
            ->paginate(9);

        return view('livewire.buys-index', ['buys' => $buys]);
    }
}
