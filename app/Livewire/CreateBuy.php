<?php

namespace App\Livewire;

use App\Models\Buy;
use App\Models\Product;
use App\Models\PurchaseItem;
use App\Models\Stock;
use App\Services\MoneyService;
use Exception;
use Livewire\Component;
use Illuminate\Support\Facades\DB;

class CreateBuy extends Component
{

    public $items = [];
    public $products;
    public $total = 0;
    public $totalToShow = '0.00';

    public $selectedProductId = null;
    public $selectedQuantity = 1;
    public $buyValueToday = null;


    public function mount()
    {

        $productList = Product::with('stock')->get();

        $this->products = $productList;
    }

    public function increaseQuantity($index)
    {
        $this->items[$index]['amount']++;

        $this->calculateItemSubtotal($index);

        $this->calculateTotal();
    }

    public function decreaseQuantity($index)
    {
        if ($this->items[$index]['amount'] > 1) {
            $this->items[$index]['amount']--;
            $this->calculateItemSubtotal($index);
            $this->calculateTotal();
        } else {
            $this->removeItem($index);
        }
    }

    public function removeItem($index)
    {
        unset($this->items[$index]);
        $this->items = array_values($this->items);
        $this->calculateTotal();
    }

    public function calculateTotal()
    {
        $this->total = 0;
        $moneyService = new MoneyService();
        foreach ($this->items as $item) {
            $this->total = $moneyService->getAdditionIntegerValue($this->total, $item['total_value']);
        }

        $this->totalToShow = $moneyService->convertIntegerToString($this->total);
    }

    public function calculateItemSubtotal($index): void
    {
        $moneyService = new MoneyService();

        $item = $this->items[$index];

        $productValue = $item['buy_value'];

        $this->items[$index]['total_value'] = $moneyService->getMultiplicationIntegerValue($productValue, $item['amount']);
        $this->items[$index]['total_value_to_show'] = $moneyService->getMultiplicationStringValue($productValue, $item['amount']);
    }

    public function addItem()
    {


        $productId = $this->selectedProductId;
        $quantity = $this->selectedQuantity;
        $buyValueToday = $this->buyValueToday;

        $this->resetErrorBag(['selectedProductId', 'selectedQuantity', 'buyValueToday']);
        $hasError = false;

        if (!$productId) {
            $this->addError('selectedProductId', 'Selecione um produto válido.');
            $hasError = true;
        }

        if ($quantity < 1) {
            $this->addError('selectedQuantity', 'Informe uma quantidade válida.');
            $hasError = true;
        }

        if (!empty($buyValueToday) && ($buyValueToday <= 0 || strlen($buyValueToday) < 3)) {
            $this->addError('buyValueToday', 'O valor mínimo é de R$ 0,01.');
            $hasError = true;
        }

        if ($hasError) return;

        $productBeingAdded = $this->products->firstWhere('id', $productId);
        if (!$productBeingAdded) return;

        $itemisAlreadyOnTheList = false;
        foreach ($this->items as $index => &$item) {
            if ($item['product_id'] == $productId) {
                $item['amount'] += $quantity;

                $itemIndex = $index;
                $itemisAlreadyOnTheList = true;
            }
        }

        $buyValue = !empty($this->buyValueToday) ? (int) $buyValueToday : $productBeingAdded->buy_value;

        if (!$itemisAlreadyOnTheList) {

            $itemData = [
                'product_id' => (int) $productId,
                'amount' => (int) $quantity,
                'buy_value' => (int) $buyValue,
                'buy_value_for_show' => (new MoneyService())->convertIntegerToString((int) $buyValue)
            ];

            $itemIndex = array_push($this->items, $itemData) - 1;
        }

        $this->calculateItemSubtotal($itemIndex);
        $this->calculateTotal();

        $this->selectedProductId = null;
        $this->selectedQuantity = 1;
        $this->buyValueToday = null;
    }

    public function submitBuy()
    {
        if (empty($this->items)) {
            $this->addError('buy', 'Seu carrinho está vazio.');
            return;
        }

        $this->validate([
            'items' => 'required|array|min:1',
            'items.*.product_id' => [
                'required',
                'integer',
                'exists:products,id',
            ],
            'items.*.amount' => [
                'required',
                'integer',
                'min:1',
            ],
        ], [
            'items.*.product_id.required' => 'Produto inválido no carrinho.',
            'items.*.product_id.exists' => 'Produto selecionado não existe.',
            'items.*.amount.required' => 'Quantidade obrigatória para todos os produtos.',
            'items.*.amount.min' => 'Quantidade deve ser no mínimo 1.',
        ]);


        try {


            DB::beginTransaction();

            $buy = Buy::create([
                'title' => now()->format('d/m/Y H:i')
            ]);

            $buyId = $buy->id;

            $moneyService = new MoneyService();

            $buyTotalValue = 0;

            foreach ($this->items as $product) {
                $productById = Product::where('id', $product['product_id'])->first();
                if (!$productById) throw new Exception('Produto não encontrado, verifique se o mesmo ainda existe.');

                $buyValue = $product['buy_value'];
                if (empty($buyValue)) throw new Exception('Erro no valor do produto:' . $productById->name . '. O valor mínimo é de R$ 0,01.');

                $totalPrice = $moneyService->getMultiplicationIntegerValue($buyValue, $product['amount']);

                $buyTotalValue += $totalPrice;

                PurchaseItem::create([
                    'product_id' => $product['product_id'],
                    'buy_id' => $buyId,
                    'amount' => $product['amount'],
                    'price_by_item' => $buyValue,
                    'total_price' => $totalPrice,
                ]);

                $stock = Stock::where('product_id', $productById->id)->first();

                if (!$stock) throw new Exception('Erro ao buscar estoque do produto: ' . $productById->name);

                $stock->quantity += $product['amount'];
                $stock->save();
            }

            $buy->total_value = $buyTotalValue;
            $buy->save();

            DB::commit();
            return redirect('/buys');
        } catch (\Exception $e) {
            DB::rollBack();
            $this->addError('buy', $e->getMessage());
            return;
        }
    }

    public function render()
    {
        return view('livewire.create-buy');
    }
}
