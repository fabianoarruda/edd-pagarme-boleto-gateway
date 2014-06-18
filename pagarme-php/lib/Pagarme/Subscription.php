<?php
class PagarMe_Subscription extends PagarMe_TransactionCommon {

	public function create() {
		if($this->plan) {
			$this->plan_id = $this->plan->id;
			unset($this->plan);
		}
		parent::create();
	}

	public function save() {
		if($this->plan) {
			$this->plan_id = $this->plan->id;
			unset($this->plan);
		}
		parent::save();
	}

	public function getTransactions() {
		try {
			$request = new PagarMe_Request(self::getUrl() . '/' . $this->id . '/transactions', 'GET');
			$response = $request->run();
			$this->transactions = PagarMe_Util::convertToPagarMeObject($response);
			return $this->transactions;
		} catch(Exception $e) {
			throw new PagarMe_Exception($e->getMessage());
		}
	}

	public function cancel() {
		try {
			$request = new PagarMe_Request(self::getUrl() . '/' . $this->id . '/cancel', 'POST');
			$response = $request->run();
			$this->refresh($response);
		} catch(Exception $e) {
			throw new PagarMe_Exception($e->getMessage());
		}
	}

	public function charge($amount) {
		try {
			if(!$this->id) {
				throw new Exception("Can't charge subscription which is not created.");
			}
			$this->amount = $amount;
			$request = new PagarMe_Request(self::getUrl(). '/' . $this->id . '/transactions', 'POST');
			$request->setParameters($this->unsavedArray());
			$response = $request->run();

			$request = new PagarMe_Request(self::getUrl() . '/' . $this->id, 'GET');
			$response = $request->run();
			$this->refresh($response);
		} catch(Exception $e) {
			throw new PagarMe_Exception($e->getMessage());
		}
	}
}
?>
