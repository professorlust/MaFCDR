<?php 

namespace BM2\SiteBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;


class Settlement {

	private $assignedRoads=-1;
	private $assignedBuildings=-1;
	private $assignedFeatures=-1;
	private $employees=-1;
	private $availableEquipment=false;

	public $corruption = false;

	public function getSize() {
/*
  1:		hamlet
  2:		small village
  3:		medium village
  4:		large village
  5:		small town
  6:		medium town
  7:		large town
  8:		small city
  9:		medium city
  10:		large city
  11:		metropolis
*/
		if ($this->getFullPopulation()<50) return 1;
		if ($this->getFullPopulation()<200) return 2;
		if ($this->getFullPopulation()<500) return 3;
		if ($this->getFullPopulation()<1000) return 4;
		if ($this->getFullPopulation()<2500) return 5;
		if ($this->getFullPopulation()<5000) return 6;
		if ($this->getFullPopulation()<10000) return 7;
		if ($this->getFullPopulation()<20000) return 8;
		if ($this->getFullPopulation()<50000) return 9;
		if ($this->getFullPopulation()<100000) return 10;
		return 11;

/*
 size:
  1:		hamlet
  2:		small village
  3:		medium village
  4:		large village
  5:		small town
  6:		medium town
  7:		large town
  8:		small city
  9:		medium city
  10:		large city
  11:		metropolis
*/
	}

	public function getType() {
		return 'settlement.size.'.$this->getSize();
	}

	public function getPic() {
		return 'size-'.$this->getSize().'-'.($this->id%5+1);
	}

	public function getFullPopulation() {
		return $this->population + $this->thralls + $this->soldiers->count();
	}

	public function getTimeToTake(Character $taker, $attackers=-1, $additional_defenders=0) {
		if ($attackers == -1) {
			$attackers = $taker->getActiveSoldiers()->count();
		}
		$enforce_claim = false;
		foreach ($this->getClaims() as $claim) {
			if ($claim->getEnforceable() && $claim->getCharacter() == $taker) {
				$enforce_claim = true;
				break;
			}
		}
		// time to take a settlement depends on its size
		// formula: 12 + log( (1+x/400)^20 ) - in hours (source of this formula: eyeballed in grapher)
		// 500 = 19h / 1000 = 23h / 2000 = 28h / 5000 = 35h / 10000 = 40h
		$time_to_take = 3600 * (12 + log10(pow(1+$this->getPopulation()/400, 20)));

		// enforcing an enforceable claim makes things a lot faster
		if ($enforce_claim) {
			$time_to_take *= 0.2;
		}

		// militia automatically opposes
		$defenders = $this->countDefenders();
		if ($defenders > 0) {
			// inactive lords don't have much support from the local militia
			if ($this->getOwner() && $this->getOwner()->getSlumbering()) {
				$defenders *= 0.25;
			}
			$time_to_take *= 1 + sqrt($defenders);
		}

		$defenders += $additional_defenders;
		$mod = ($defenders+10) / ($attackers+10);
		if ($mod < 1.0) {
			$mod = sqrt($mod);
		}

		// inactive lord = half time, in addition to the change above (which also includes inactive ones)
		if ($this->getOwner() && $this->getOwner()->getAlive() && $this->getOwner()->getSlumbering()) {
			$mod *= 0.5;
		}
		$time_to_take *= $mod;

		return round($time_to_take);
	}

	public function getRecruitLimit($ignore_recruited = false) {
		// TODO: this should take population density, etc. into account, I think, which means it would have to be moved into the military service
		$max = ceil($this->population/10);
		if ($ignore_recruited) {
			return $max;
		} else {
			return max(0, $max - $this->recruited);
		}
	}

	public function findResource(ResourceType $type) {
		$resource = $this->getResources()->filter(
			function($entry) use ($type) {
				return ($entry->getType()->getId()==$type->getId());
			}
		);
		return $resource->first();
	}

	public function getNameWithOwner() {
		if ($this->getOwner()) {
			return $this->getName().' ('.$this->getOwner()->getName().')';
		} else {
			return $this->getName();
		}
	}

	public function getMilitia() {
		return $this->getSoldiers()->filter(
			function($entry) {
				return ($entry->isMilitia());
			}
		);
	}
	public function getActiveMilitia() {
		return $this->getSoldiers()->filter(
			function($entry) {
				return ($entry->isActive() && $entry->isMilitia());
			}
		);
	}
	public function getRecruits() {
		return $this->getSoldiers()->filter(
			function($entry) {
				return ($entry->isRecruit());
			}
		);
	}

	public function getActiveMilitiaByType() {
		return $this->getSoldiersByType(true, true);
	}
	public function getMilitiaByType($active_only=false) {
		return $this->getSoldiersByType(true, false);
	}
	public function getRecruitsByType() {
		return $this->getSoldiersByType(false, false);
	}

	public function getSoldiersByType($militia=true, $active_only=false) {
		$data = array();
		if ($militia) {
			if ($active_only) {
				$soldiers = $this->getActiveMilitia();
			} else {
				$soldiers = $this->getMilitia();
			}			
		} else {
			$soldiers = $this->getRecruits();
		}
		foreach ($soldiers as $soldier) {
			$type = $soldier->getType();
			if (isset($data[$type])) {
				$data[$type]++;
			} else {
				$data[$type] = 1;
			}
		}
		return $data;
	}


	public function findDefenders() {
		// anyone with a "defend settlement" action who is nearby
		$defenders = new ArrayCollection;
		foreach ($this->getRelatedActions() as $act) {
			if ($act->getType()=='settlement.defend') {
				$defenders->add($act->getCharacter());
			}
		}
		return $defenders;
	}

	public function countDefenders() {
		$defenders = 0;
		foreach ($this->findDefenders() as $char) {
			$defenders += $char->getActiveSoldiers()->count();
		}
		$militia = $this->getActiveMilitia()->count();
		return $militia + $defenders;
	}

	public function getActiveBuildings() {
		return $this->getBuildings()->filter(
			function($entry) {
				return ($entry->getActive());
			}
		);
	}

	public function getBuildingByType(BuildingType $type) {
		$present = $this->getBuildings()->filter(
			function($entry) use ($type) {
				return ($entry->getType() == $type);
			}
		);
		if ($present) return $present->first();
		return false;
	}
	public function hasBuilding(BuildingType $type, $with_inactive=false) {
		$has = $this->getBuildingByType($type);
		if (!$has) return false;
		if ($with_inactive) return true;
		return $has->isActive();
	}

	public function getBuildingByName($name) {
		$present = $this->getBuildings()->filter(
			function($entry) use ($name) {
				return ($entry->getType()->getName() == $name);
			}
		);
		if ($present) return $present->first();
		return false;
	}
	public function hasBuildingNamed($name) {
		$has = $this->getBuildingByName($name);
		if (!$has) return false;
		return $has->isActive();
	}

	public function isFortified() {
		$walls = $this->getBuildings()->filter(
			function($entry) {
				if (!$entry->isActive()) return false;
				return in_array($entry->getType()->getName(), array('Palisade', 'Wood Wall', 'Stone Wall'));
			}
		);
		if (!$walls->isEmpty() && $this->isDefended()) return true;
		return false;
	}

	public function getAvailableWorkforce() {
		return $this->getPopulation() + $this->getThralls() - $this->getRoadWorkers() - $this->getBuildingWorkers() - $this->getFeatureWorkers() - $this->getEmployees();
	}
	public function getAvailableWorkforcePercent() {
		if ($this->getPopulation()<=0) return 0;
		$employeespercent = $this->getEmployees()/$this->getPopulation();
		return 1 - $this->getRoadWorkersPercent() - $this->getBuildingWorkersPercent() - $this->getFeatureWorkersPercent() - $employeespercent;
	}
	public function getRoadWorkersPercent() {
		if ($this->assignedRoads==-1) {
			$this->assignedRoads = 0;
			foreach ($this->getGeoData()->getRoads() as $road) {
				if ($road->getWorkers()>0) { $this->assignedRoads += $road->getWorkers(); }
			}
		}

		return $this->assignedRoads;
	}
	public function getRoadWorkers() {
		return round($this->getRoadWorkersPercent() * $this->getPopulation());
	}
	public function getBuildingWorkersPercent() {
		if ($this->assignedBuildings==-1) {
			$this->assignedBuildings = 0;
			foreach ($this->getBuildings() as $building) {
				if ($building->getWorkers()>0) { $this->assignedBuildings += $building->getWorkers(); }
			}
		}

		return $this->assignedBuildings;
	}
	public function getBuildingWorkers() {
		return round($this->getBuildingWorkersPercent() * $this->getPopulation());
	}	
	public function getFeatureWorkersPercent($force_recalc=false) {
		if ($force_recalc) $this->assignedFeatures=-1;
		if ($this->assignedFeatures==-1) {
			$this->assignedFeatures = 0;
			foreach ($this->getGeoData()->getFeatures() as $feature) {
				if ($feature->getWorkers()>0) { $this->assignedFeatures += $feature->getWorkers(); }
			}
		}

		return $this->assignedFeatures;
	}
	public function getFeatureWorkers($force_recalc=false) {
		return round($this->getFeatureWorkersPercent($force_recalc) * $this->getPopulation());
	}

	public function getEmployees() {
		if ($this->employees==-1) {
			$this->employees = 0;
			foreach ($this->getBuildings() as $building) {
				if ($building->isActive()) { $this->employees += $building->getEmployees(); }
			}
		}

		return $this->employees;
	}


	public function getTrainingPoints() {
		return round(pow($this->population/10, 0.75)*5);
	}
	public function getSingleTrainingPoints() {
		// the amount of training a single soldier can at most expect per day
		return max(1,sqrt(sqrt($this->population)/2));
	}

	public function isDefended() {
		if ($this->countDefenders()>0) return true;
		return false;
	}
	/* Leftover code from when we were planning to make settlements subordinate to each other.
	Doing this would make calculating region resources and the like a literal pain, as we'd have to add a bunch of conditions
	that help the game determine if the settlement is supposed to have resources or not.
	
	The better idea is to make places entirely NOT settlements, and have them operate on separate code.
	
	public function getMinorSettlementWorkers($include_me=true) {
		$total;
		# $include_me set to true will include the settlement we're looking at. 
		if ($include_me) {
			$total += round($this->getBuildingWorkers() * $this->getPopulation());
			foreach ($settlement->getInferiors() as $minor) {
				$total += round($minor->getBuildingWorkersPercent() * $minor->getPopulation());
			}
		} else {
			foreach ($settlement->getInferiors() as $minor) {
				$total += round($minor->getBuildingWorkersPercent() * $minor->getPopulation());
			}
		}
		return $total;
	}
	
	public function getMinorSettlementWorkersPercent($include_me=true) {
		$total;
		if ($include_me) {
			$total += $this->getBuildingWorkersPercent();
			foreach ($settlement->findSuperior()->getInferiors() as $minor) {
				$total =+ $minor->getBuildingWorkersPercent();
			}
		} else {
			foreach ($settlement->findSuperior()->getInferiors() as $minor) {
				$total =+ $minor->getBuildingWorkersPercent();
			}
		}
		return $total;
	}
	
	*/

	
}
