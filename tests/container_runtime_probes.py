"""Non-development image probes; synthetic rows roll back, PostgreSQL sequences may advance."""

CURRENT_CONSULTATION_AUTHORIZATION = r"""require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('production')||config('app.debug')||\App\Models\User::count()!==4||\App\Models\User::where('name','like','بررسی کانتینر %')->count()!==4){throw new RuntimeException('Only the owned four-user production-mode fixture.');}

$tables=['consultation_slots','consultations','consultation_requests','consultation_grants','consultation_grant_uses','payments','audit_events','notifications'];
$fingerprint=function()use($tables){$counts=[];foreach($tables as $table){$counts[$table]=\Illuminate\Support\Facades\DB::table($table)->count();}$counts['account_state']=\App\Models\User::orderBy('id')->get(['id','is_active','access_revision'])->toJson();return $counts;};
$before=$fingerprint();$checks=0;
$denied=function(callable $operation)use(&$checks){try{$operation();throw new RuntimeException('Unexpected production write authorization.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==403){throw $e;}$checks++;}};
\Illuminate\Support\Facades\DB::beginTransaction();
try{
$admin=\App\Models\User::where('phone','989121234562')->firstOrFail();$student=\App\Models\User::where('phone','989121234560')->firstOrFail();$coach=\App\Models\User::where('phone','989121234561')->firstOrFail();
$service=app(\App\Services\ConsultationService::class);$packages=app(\App\Services\ConsultationPackages::class);$billing=app(\App\Services\ConsultationBilling::class);
$start=\Carbon\CarbonImmutable::now()->addDays(30)->startOfMinute();
$slot=$service->createSlot($coach,$coach,$start,60,'meet',null);
$bookedSlot=$service->createSlot($coach,$coach,$start->addDay(),60,'meet',null);
$booking=$service->book($student,$bookedSlot,'Software-only rollback acceptance');
$request=$service->request($student,'Software-only rollback acceptance','Software-only, no actual contact');
$data=['title'=>'Software-only rollback acceptance','source_reference'=>'SOFTWARE-ONLY-ROLLBACK63','total_sessions'=>3,'session_minutes'=>60,'valid_until'=>now()->addMonths(2)->toDateString(),'revision'=>1,'active'=>false];
$grant=$packages->configure($admin,$student,$coach,$data);
$expected=$fingerprint();$records=[$slot->fresh()->getAttributes(),$booking->fresh()->getAttributes(),$request->fresh()->getAttributes(),$grant->fresh()->getAttributes()];
\Illuminate\Support\Facades\DB::table('users')->where('id',$admin->id)->update(['is_active'=>false]);
$denied(fn()=>$service->createSlot($admin,$coach,$start->addDays(2),60,'meet',null));
$denied(fn()=>$service->publish($admin,$slot,false));
$denied(fn()=>$service->meeting($admin,$slot,'https://meet.google.com/xyz-abcd-efg'));
$denied(fn()=>$service->resolve($admin,$request,1,'Software-only rollback acceptance'));
$denied(fn()=>$service->change($admin,$booking,1,'cancel','Software-only rollback acceptance',null));
$denied(fn()=>$packages->configure($admin,$student,$coach,[...$data,'source_reference'=>'SOFTWARE-ONLY-DENIED63']));
$denied(fn()=>$packages->configure($admin,$student,$coach,$data,$grant));
\Illuminate\Support\Facades\DB::table('users')->where('id',$admin->id)->update(['is_active'=>true]);
if($fingerprint()!==$expected||$records!==[$slot->fresh()->getAttributes(),$booking->fresh()->getAttributes(),$request->fresh()->getAttributes(),$grant->fresh()->getAttributes()]){throw new RuntimeException('A denied production management operation changed state.');}
$paid=[];
foreach([5,6]as $offset){$paidSlot=$service->createSlot($admin,$coach,$start->addDays($offset),60,'meet',null,1000000);$paidSlot->update(['booked'=>true]);$paid[]=\App\Models\Consultation::create(['student_id'=>$student->id,'consultation_slot_id'=>$paidSlot->id,'topic'=>'Software-only rollback payment','status'=>'pending_payment','version'=>1,'amount'=>1000000,'listed_amount'=>1000000,'expires_at'=>now()->addMinutes(10)]);}
$payment=$billing->attempt($student,$paid[0]);$expected=$fingerprint();$paymentState=$payment->fresh()->getAttributes();
\Illuminate\Support\Facades\DB::table('users')->where('id',$student->id)->update(['is_active'=>false]);
$denied(fn()=>$billing->attempt($student,$paid[0]));$denied(fn()=>$billing->attempt($student,$paid[1]));
\Illuminate\Support\Facades\DB::table('users')->where('id',$student->id)->update(['is_active'=>true]);
if($fingerprint()!==$expected||$payment->fresh()->getAttributes()!==$paymentState){throw new RuntimeException('A denied production payment attempt changed state.');}
}finally{\Illuminate\Support\Facades\DB::rollBack();}
if($fingerprint()!==$before||$checks!==9){throw new RuntimeException('Production authorization acceptance did not roll back all fixture rows.');}
echo 'NINE_PRODUCTION_AUTHORIZATION_FIXTURE_ROWS_ROLLED_BACK';
"""


CURRENT_FINANCIAL_AUTHORIZATION = r"""require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('production')||config('app.debug')||\App\Models\User::count()!==4||\App\Models\User::where('name','like','بررسی کانتینر %')->count()!==4){throw new RuntimeException('Only the owned four-user production-mode fixture.');}
$tables=['users','role_user','organizations','organization_user','organization_invoices','organization_payment_allocations','consultation_products','package_purchases','consultation_grants','consultation_grant_uses','payments','payment_receipts','receipt_claims','refunds','refund_events','audit_events','notifications'];
$fingerprint=function()use($tables){$state=[];foreach($tables as $table){$rows=\Illuminate\Support\Facades\DB::table($table)->get()->map(fn($row)=>json_encode($row,JSON_THROW_ON_ERROR))->all();sort($rows,SORT_STRING);$state[$table]=$rows;}foreach(['refunds','receipts']as $disk){$files=\Illuminate\Support\Facades\Storage::disk($disk)->allFiles();sort($files,SORT_STRING);$state['files_'.$disk]=$files;}return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));};
$before=$fingerprint();$checks=0;$path=null;
$denied=function(callable $operation)use(&$checks){try{$operation();throw new RuntimeException('Unexpected production financial authorization.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==403){throw $e;}$checks++;}};
\Illuminate\Support\Facades\DB::beginTransaction();
try{
$admin=\App\Models\User::where('phone','989121234562')->firstOrFail();$student=\App\Models\User::where('phone','989121234560')->firstOrFail();$coach=\App\Models\User::where('phone','989121234561')->firstOrFail();$manager=\App\Models\User::where('phone','989121234563')->firstOrFail();
if(!$admin->is_active||!$student->is_active||!$coach->is_active||!$manager->is_active){throw new RuntimeException('Preserve inactive source accounts.');}
config(['platform.finance.transfer_destination'=>'6037991234567890','platform.finance.transfer_holder'=>'SOFTWARE-ONLY-NO-REAL-BANK-ACCOUNT']);
$packages=app(\App\Services\PackageBilling::class);$billing=app(\App\Services\OrganizationBilling::class);$refunds=app(\App\Services\RefundService::class);
$productData=['coach_id'=>$coach->id,'title'=>'Software-only rollback67 product','description'=>'No real contract or funds','sessions'=>2,'session_minutes'=>60,'valid_days'=>30,'price'=>100000,'published'=>true,'manual_payment_enabled'=>false];
$product=$packages->configure($admin,$productData);$purchase=$packages->reserve($student,$product,1);$purchaseState=$purchase->fresh()->getAttributes();
$packages->configure($admin,[...$productData,'revision'=>1,'price'=>200000,'title'=>'Software-only rollback67 next edition'],$product);$again=$packages->reserve($student,$product->fresh(),2);
if($again->id!==$purchase->id||$purchase->fresh()->getAttributes()!==$purchaseState||$again->amount!==1000000){throw new RuntimeException('Frozen production purchase changed on reuse.');}
$reuseProduct=$packages->configure($admin,[...$productData,'title'=>'Software-only rollback67 pending reuse']);$reusePurchase=$packages->reserve($student,$reuseProduct,1);
$organization=\App\Models\Organization::create(['name'=>'Software-only rollback67 organization','type'=>'company','status'=>'active']);$organization->members()->attach($manager->id,['kind'=>'manager']);
$definition=['title'=>'Software-only rollback67 invoice','contract_reference'=>'SOFTWARE-ONLY-ROLLBACK67','due_date'=>now()->addWeek()->toDateString(),'organization_license_id'=>null,'lines'=>[['title'=>'No real service or funds','quantity'=>1,'unit_price'=>100000]]];
$draft=$billing->draft($admin,$organization,$definition);
$issued=function()use($billing,$admin,$organization,$definition){$invoice=$billing->draft($admin,$organization,$definition);$billing->issue($admin,$invoice,1);return $invoice->fresh();};
$newInvoice=$issued();$reuseInvoice=$issued();$pending=$billing->attempt($manager,$reuseInvoice);$reviewInvoice=$issued();$refundInvoice=$issued();
$serial=0;$captured=function(string $kind)use(&$serial,$purchase,$refundInvoice,$student,$manager){$serial++;return \App\Models\Payment::create(['user_id'=>$kind==='package'?$student->id:$manager->id,'package_purchase_id'=>$kind==='package'?$purchase->id:null,'organization_invoice_id'=>$kind==='organization'?$refundInvoice->id:null,'amount'=>1000000,'method'=>'gateway','status'=>'review_required','reference'=>'SOFTWARE-ONLY-ROLLBACK67-'.$serial,'captured_at'=>now(),'paid_at'=>now()]);};
$packageReview=$captured('package');$packageComplete=$captured('package');$packageRequest=$captured('package');$packageAllocate=$captured('package');
$organizationReview=$captured('organization');$organizationComplete=$captured('organization');$organizationRequest=$captured('organization');
$iban='IR820540102680020817909002';
$pkgRefundReview=$refunds->request($student,$packageReview,$iban,'Software-only rollback67 request',0);$pkgRefundComplete=$refunds->request($student,$packageComplete,$iban,'Software-only rollback67 request',0);$refunds->review($admin,$pkgRefundComplete,1,'approve','Software-only rollback approval');
$orgRefundReview=$refunds->requestOrganization($manager,$organizationReview,$iban,'Software-only beneficiary','Software-only rollback67 request',0);$orgRefundComplete=$refunds->requestOrganization($manager,$organizationComplete,$iban,'Software-only beneficiary','Software-only rollback67 request',0);$refunds->review($admin,$orgRefundComplete,1,'approve','Software-only rollback approval');
$placeholder=function(\App\Models\Payment $payment,string $label){return \App\Models\PaymentReceipt::create(['payment_id'=>$payment->id,'file_path'=>'software-only-placeholder-not-a-real-bank-document/'.$label,'file_name'=>'software-only-placeholder.pdf','file_hash'=>hash('sha256','SOFTWARE-ONLY-PLACEHOLDER67-'.$label),'destination'=>'6037991234567890','transfer_reference'=>'SOFTWAREONLY67'.$label,'transfer_date'=>now()->toDateString()]);};
$packageReceiptPayment=\App\Models\Payment::create(['user_id'=>$student->id,'package_purchase_id'=>$purchase->id,'amount'=>1000000,'method'=>'manual','status'=>'pending_review']);$packageReceipt=$placeholder($packageReceiptPayment,'PACKAGE');
$orgReceiptPayment=\App\Models\Payment::create(['user_id'=>$manager->id,'organization_invoice_id'=>$reviewInvoice->id,'amount'=>1000000,'method'=>'manual','status'=>'pending_review']);$orgReceipt=$placeholder($orgReceiptPayment,'ORGANIZATION');
$reviewData=['action'=>'approve','expected_review_version'=>0,'verified_amount'=>100000,'statement_verified'=>true,'review_note'=>'Software-only rollback67 review'];
$path=tempnam(sys_get_temp_dir(),'kardorost-auth67-');if($path===false){throw new RuntimeException('Software fixture temp file unavailable.');}file_put_contents($path,"%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-BANK-TRANSFER67\n%%EOF\n");$file=new \Illuminate\Http\UploadedFile($path,'software-only-rollback67.pdf','application/pdf',null,true);
$completion=['version'=>2,'amount'=>100000,'destination'=>$iban,'transfer_reference'=>'SOFTWAREONLYROLLBACK67','transfer_date'=>now()->toDateString(),'statement_verified'=>true,'beneficiary_verified'=>true];
$expected=$fingerprint();
\Illuminate\Support\Facades\DB::table('users')->where('id',$admin->id)->update(['is_active'=>false]);
$denied(fn()=>$packages->configure($admin,$productData));$denied(fn()=>$packages->configure($admin,[...$productData,'revision'=>2],$product->fresh()));$denied(fn()=>$packages->resolve($admin,$packageAllocate));$denied(fn()=>$packages->review($admin,$packageReceipt,$reviewData));
$denied(fn()=>$billing->draft($admin,$organization,$definition));$denied(fn()=>$billing->draft($admin,$organization,$definition,$draft,1));$denied(fn()=>$billing->issue($admin,$draft,1));$denied(fn()=>$billing->void($admin,$draft,1,'Software-only rollback cancellation'));$denied(fn()=>$billing->review($admin,$orgReceipt,$reviewData));$denied(fn()=>$billing->review($admin,$orgReceipt,[...$reviewData,'action'=>'reject']));
foreach([$pkgRefundReview,$orgRefundReview]as $refund){$denied(fn()=>$refunds->review($admin,$refund,1,'approve','Software-only rollback review'));$denied(fn()=>$refunds->review($admin,$refund,1,'reject','Software-only rollback review'));}
foreach([$pkgRefundComplete,$orgRefundComplete]as $refund){$denied(fn()=>$refunds->complete($admin,$refund,$completion,$file));}
\Illuminate\Support\Facades\DB::table('users')->where('id',$admin->id)->update(['is_active'=>true]);
if($fingerprint()!==$expected){throw new RuntimeException('Denied production administration changed financial rows or stored files.');}
\Illuminate\Support\Facades\DB::table('users')->where('id',$student->id)->update(['is_active'=>false]);
$denied(fn()=>$packages->reserve($student,$reuseProduct,1));$denied(fn()=>$refunds->request($student,$packageRequest,$iban,'Software-only rollback request',0));
\Illuminate\Support\Facades\DB::table('users')->where('id',$student->id)->update(['is_active'=>true]);
if($fingerprint()!==$expected){throw new RuntimeException('Denied production owner changed financial rows or stored files.');}
\Illuminate\Support\Facades\DB::table('users')->where('id',$manager->id)->update(['is_active'=>false]);
$denied(fn()=>$billing->attempt($manager,$newInvoice));$denied(fn()=>$billing->attempt($manager,$reuseInvoice));$denied(fn()=>$billing->receipt($manager,$newInvoice,$file,'SOFTWAREONLYROLLBACK67',now()->toDateString()));$denied(fn()=>$refunds->requestOrganization($manager,$organizationRequest,$iban,'Software-only beneficiary','Software-only rollback request',0));
\Illuminate\Support\Facades\DB::table('users')->where('id',$manager->id)->update(['is_active'=>true]);
if($fingerprint()!==$expected){throw new RuntimeException('Denied production manager changed financial rows or stored files.');}
}finally{\Illuminate\Support\Facades\DB::rollBack();if($path!==null&&is_file($path)){unlink($path);}}
if($checks!==22||$fingerprint()!==$before){throw new RuntimeException('Production financial acceptance did not preserve all source rows and stored file paths.');}
echo 'TWENTY_TWO_PRODUCTION_FINANCIAL_REFUSALS_FROZEN_PURCHASE_AND_FIXTURE_ROWS_ROLLED_BACK';
"""
