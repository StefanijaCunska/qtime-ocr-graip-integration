<?php
namespace App;

use Illuminate\Database\Eloquent\Model;
use Session;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7;
use GuzzleHttp\Exception\ClientException;
use App\Pastkastite;
use Illuminate\Support\Facades\Log;

class OcrGraip extends Model
{
	protected $table='ocr_graip';
	
	public const headers=[
		'Authorization'=>''
	];
	;
	public const flowId='';
	
	public function pastkastite(){
		return $this->belongsTo(Pastkastite::class);
	}
	
	public static function sendData($arr){
		
		$multipartArray=[];
		foreach($arr as $dokId=>$dokPath){
			if(file_exists($dokPath)){
				$tempArray=[];
				$tempArray['name']='files';
				$tempArray['contents']=fopen($dokPath,'r');
				$tempArray['filename']=basename($dokPath);
				
				$multipartArray[]=$tempArray;
				
			}
		}
		$multipartArray[]=[
						'name'     => 'process',
						'contents' => true
					];
		$multipartArray[]=[
						'name'     => 'pagesToProcess',
						'contents' => '1, -1'
					];

		try{
			$client = new Client(['base_uri' => '']);
			$request = $client->request('POST', '/api/v1/'.self::flowId.'/request/bulk',[
				'headers'=>self::headers,
				'multipart' =>array_values($multipartArray)							
			]);
			if ($body = $request->getBody()) {
				$body=json_decode($body);
				$packageId=$body->packageId;
				foreach($arr as $dokId=>$dokPath){
					$graipData=new self();
					$graipData->package_id=$packageId;
					$graipData->pastkastite_id=$dokId;
					$graipData->name=basename($dokPath);
					$graipData->save();
				}
			}
		}catch(ClientException $e){
			dd($e);
		}
	}
	
	public static function receiveData(){
		$packageList=self::whereNull('response')->get()->unique('package_id');
		$client = new Client(['base_uri' => '']);
		foreach($packageList as $row){
			$packageItems=OcrGraip::where('package_id','=',$row->package_id)->get()->keyBy('id');	
			try{
				$request = $client->request('GET', '/api/v1/'.self::flowId.'/request/list',[
				'headers'=>self::headers,
					'query'=>[
						'offset'=>0,
						'limit'=>20,
						'filter'=>json_encode(['package'=>$row->package_id])
					],
				]);
			}catch(Exception $e){
				Log::info("graip receive error");
			}

			if ($body = $request->getBody()) {
				$body=json_decode($body);
				$items=$body->items;
				foreach($items as $item){
					$graipRecord=self::where('package_id','=',$row->package_id)->where('name','=',$item->title)->first();
					if($graipRecord){
						unset($packageItems[$graipRecord->id]);
						$request = $client->request('GET', '/api/v1/'.self::flowId.'/request/'.$item->id.'/export/json',[
							'headers'=>self::headers
						]);
						if ($body = $request->getBody()) {
							$body=json_decode($body);
							$body=$body[0];
							$responseData=$body->headers;
							$graipRecord->graip_id=$item->id;
							$graipRecord->page_count=$item->page_count;
							$graipRecord->response=json_encode($body);
							$graipRecord->save();
							$pastkastite=$graipRecord->pastkastite;
							if($pastkastite){
								if($pastkastite->ocr!='2'){
									$companyData=false;
									$vendorData=false;
									$klientsRegNr=getKlientsDatiValue($pastkastite->klients_id,'vrn');
									if(isset($responseData->StoreName) || isset($responseData->StoreRegistrationNumber) ||isset($responseData->StoreTaxNumber)){
										$customerRegistrationNumber="";
										$customerTaxNumber="";
										
										if(isset($responseData->StoreTaxNumber) && !empty($responseData->StoreTaxNumber)){
											$customerTaxNumber=$responseData->StoreTaxNumber;
											if($customerRegistrationNumber=="" && $customerTaxNumber!=""){
												if(strpos($customerTaxNumber, 'LV') !== false || strpos($customerTaxNumber, 'PL') !== false){
													$customerRegistrationNumber=substr($customerTaxNumber,2);
												}
											}
										}
										if(isset($responseData->StoreRegistrationNumber) && !empty($responseData->StoreRegistrationNumber)){
											$customerRegistrationNumber=$responseData->StoreRegistrationNumber;
										}
										if($klientsRegNr!=$customerRegistrationNumber){
											$pastkastite->reg_nr_str=$customerRegistrationNumber;
											$pastkastite->vrn_str=$customerTaxNumber;
										}
										$piegadatajs=null;
										if($pastkastite->reg_nr_str){
											$piegadatajs=Lursoft_piegadataji::connect('1g')->where('reg_nr','=',$pastkastite->reg_nr_str)->first();
										}
										if($piegadatajs){
											$pastkastite->nosaukums_str=$piegadatajs->nosaukums;
										}else{
											if(isset($responseData->StoreName)){
												$pastkastite->nosaukums_str=str_replace(['„','“','SIA', 'AS', 'UAB', '"', "'"],'',$responseData->StoreName);
											}
										}
										if(isset($responseData->TransactionDate)){
											$pastkastite->datums_str=date('d.m.Y',strtotime($responseData->TransactionDate));
										}
										if(isset($responseData->Total) && isset($responseData->Total->amount)){
											$pastkastite->summa_str=number_format($responseData->Total->amount,2,'.','');;
										}
										if(isset($responseData->ReceiptNumber)){
											$pastkastite->numurs_str=str_replace('#','',$responseData->ReceiptNumber);
										}
										$pastkastite->ocr=1;
										if(!empty($pastkastite->reg_nr_str) && !empty($pastkastite->datums_str) && !empty($pastkastite->numurs_str) && !empty($pastkastite->summa_str)){
											$pastkastite->ocr_full=1;
										}
										
									}else{
										if(isset($responseData->CustomerRegistrationNumber) || isset($responseData->CustomerTaxNumber)){
											$customerRegistrationNumber="";
											$customerTaxNumber="";
											
											if(isset($responseData->CustomerTaxNumber) && !empty($responseData->CustomerTaxNumber)){
												$customerTaxNumber=$responseData->CustomerTaxNumber;
												if($customerRegistrationNumber=="" && $customerTaxNumber!=""){
													if(strpos($customerTaxNumber, 'LV') !== false || strpos($customerTaxNumber, 'PL') !== false){
														$customerRegistrationNumber=substr($customerTaxNumber,2);
													}
												}
											}
											if(isset($responseData->CustomerRegistrationNumber) && !empty($responseData->CustomerRegistrationNumber)){
												$customerRegistrationNumber=$responseData->CustomerRegistrationNumber;
											}
											if($klientsRegNr!=$customerRegistrationNumber && $customerRegistrationNumber!=""){
												$pastkastite->reg_nr_str=$customerRegistrationNumber;
												$pastkastite->vrn_str=$customerTaxNumber;
												$companyData=true;
											}
										}
										if((isset($responseData->VendorRegistrationNumber) || isset($responseData->VendorTaxNumber)) && $companyData==false){
											$vendorRegistrationNumber="";
											$vendorTaxNumber="";
											
											if(isset($responseData->VendorTaxNumber) && !empty($responseData->VendorTaxNumber)){
												$vendorTaxNumber=$responseData->VendorTaxNumber;
												if($vendorRegistrationNumber=="" && $vendorTaxNumber!=""){
													if(strpos($vendorTaxNumber, 'LV') !== false || strpos($vendorTaxNumber, 'PL') !== false){
														$vendorRegistrationNumber=substr($vendorTaxNumber,2);
													}
												}
											}
											if(isset($responseData->VendorRegistrationNumber) && !empty($responseData->VendorRegistrationNumber)){
												$vendorRegistrationNumber=$responseData->VendorRegistrationNumber;
											}
											if($klientsRegNr!=$vendorRegistrationNumber && $vendorRegistrationNumber!=""){
												$pastkastite->reg_nr_str=$vendorRegistrationNumber;
												$pastkastite->vrn_str=$vendorTaxNumber;
												$vendorData=true;
											}
										}
										
										$piegadatajs=null;
										if($pastkastite->reg_nr_str){
											$piegadatajs=Lursoft_piegadataji::connect('1g')->where('reg_nr','=',$pastkastite->reg_nr_str)->first();
										}
										if($piegadatajs){
											$pastkastite->nosaukums_str=$piegadatajs->nosaukums;
										}else{
											if($companyData && isset($responseData->CustomerCompanyName)){
												$pastkastite->nosaukums_str=str_replace(['„','“','SIA', 'AS', 'UAB', '"', "'"],'',$responseData->CustomerCompanyName);
											}
											if($vendorData && isset($responseData->VendorName)){
												$pastkastite->nosaukums_str=str_replace(['„','“','SIA', 'AS', 'UAB', '"', "'"],'',$responseData->VendorName);
											}
										}
										
										
										if(isset($responseData->InvoiceDate)){
											$pastkastite->datums_str=date('d.m.Y',strtotime($responseData->InvoiceDate));
										}
										if(isset($responseData->DueDate)){
											$pastkastite->termins_str=date('d.m.Y',strtotime($responseData->DueDate));
										}
										if(isset($responseData->InvoiceTotal) && isset($responseData->InvoiceTotal->amount)){
											$pastkastite->summa_str=number_format($responseData->InvoiceTotal->amount,2,'.','');
										}
										if(isset($responseData->InvoiceId)){
											$pastkastite->numurs_str=$responseData->InvoiceId;
										}
										$pastkastite->ocr=1;
										if(!empty($pastkastite->reg_nr_str) && !empty($pastkastite->datums_str) && !empty($pastkastite->numurs_str) && !empty($pastkastite->summa_str)){
											$pastkastite->ocr_full=1;
										}
									}
									$pastkastite->save();
									
									$ocrLog=new Ocr_log();
									$ocrLog->pastkastite_id=$pastkastite->id;
									$ocrLog->ocr=9;
									$ocrLog->save();
								}
							}
						}
					}
				}
				
			}
			foreach($packageItems as $packageItem){
				$packageItem->graip_id='0';
				$packageItem->response='-';
				$packageItem->save();
			}
		}
	}
}