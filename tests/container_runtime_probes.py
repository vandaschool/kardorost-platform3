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


CURRENT_COURSE_ORGANIZATION_AUTHORIZATION = r"""require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!app()->environment('production')||config('app.debug')||\App\Models\User::count()!==4||\App\Models\User::where('name','like','بررسی کانتینر %')->count()!==4){throw new RuntimeException('Only the owned four-user production-mode fixture.');}
\Illuminate\Support\Facades\Http::preventStrayRequests();
$tables=['users','role_user','courses','cohorts','cohort_installments','cohort_consultation_benefits','enrollment_consultation_benefits','enrollments','installments','payments','payment_receipts','receipt_claims','payment_allocations','consultation_grants','consultation_grant_uses','organizations','organization_user','organization_invitations','organization_invitation_imports','invitation_sms','organization_licenses','organization_seats','audit_events','notifications','jobs'];
$fingerprint=function()use($tables){$state=[];foreach($tables as $table){$rows=\Illuminate\Support\Facades\DB::table($table)->get()->map(fn($row)=>json_encode($row,JSON_THROW_ON_ERROR))->all();sort($rows,SORT_STRING);$state[$table]=$rows;}foreach(['receipts','refunds','learning','support']as $disk){$files=\Illuminate\Support\Facades\Storage::disk($disk)->allFiles();sort($files,SORT_STRING);$state['files_'.$disk]=$files;}return hash('sha256',json_encode($state,JSON_THROW_ON_ERROR));};
$before=$fingerprint();$checks=0;$temporary=[];
$denied=function(callable $operation)use(&$checks){try{$operation();throw new RuntimeException('Unexpected production course or organization authorization.');}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){if($e->getStatusCode()!==403){throw $e;}$checks++;}};
\Illuminate\Support\Facades\DB::beginTransaction();
try{
$admin=\App\Models\User::where('phone','989121234562')->firstOrFail();$student=\App\Models\User::where('phone','989121234560')->firstOrFail();$coach=\App\Models\User::where('phone','989121234561')->firstOrFail();$manager=\App\Models\User::where('phone','989121234563')->firstOrFail();
foreach([[$admin,'admin'],[$student,'student'],[$coach,'coach'],[$manager,'org_admin']]as [$actor,$role]){if(!$actor->is_active||!$actor->phone_verified_at||!$actor->hasRole($role)){throw new RuntimeException('Preserve changed source account eligibility.');}}
config(['platform.sms.driver'=>'disabled','platform.sms.melipayamak.invitations_enabled'=>false,'platform.finance.transfer_destination'=>'6037991234567890','platform.finance.transfer_holder'=>'SOFTWARE-ONLY-NO-REAL-BANK-ACCOUNT']);
$request=function(string $path,array $data,\App\Models\User $actor){$request=\Illuminate\Http\Request::create($path,'POST',$data);$request->setUserResolver(fn()=>$actor);return $request;};
$courseData=['title'=>'Software-only rollback72 course','description'=>'No real course, contract or funds','stage'=>1,'price'=>100000,'capacity'=>10,'format'=>'hybrid','is_published'=>true];
app(\App\Http\Controllers\CourseController::class)->store($request('/admin/courses',$courseData,$admin));$course=\App\Models\Course::where('title',$courseData['title'])->sole();$personal=$course->cohorts()->sole();$personal->update(['manual_payment_enabled'=>true]);
$cohort=function(string $title,int $price=1000000){$course=\App\Models\Course::create(['title'=>$title,'slug'=>(string)\Illuminate\Support\Str::uuid(),'description'=>'Software-only rollback fixture','stage'=>1,'price'=>$price,'is_published'=>true]);return \App\Models\Cohort::create(['course_id'=>$course->id,'title'=>'Software-only first cohort','format'=>'hybrid','capacity'=>10,'manual_payment_enabled'=>true]);};
$group=$cohort('Software-only rollback72 group');$newGroup=$cohort('Software-only rollback72 next group');$freshPaid=$cohort('Software-only rollback72 fresh paid');$free=$cohort('Software-only rollback72 free',0);$renew=$cohort('Software-only rollback72 renewal');$newInvoiceCohort=$cohort('Software-only rollback72 new payment');
$benefits=app(\App\Services\CourseConsultationBenefits::class);$enrollments=app(\App\Services\EnrollmentService::class);$billing=app(\App\Services\BillingService::class);$receipts=app(\App\Services\ReceiptService::class);$membership=app(\App\Services\OrganizationMembership::class);$seats=app(\App\Services\OrganizationEnrollment::class);
$benefit=['revision'=>0,'enabled'=>true,'coach_id'=>$coach->id,'title'=>'Software-only frozen two sessions','sessions'=>2,'session_minutes'=>60,'valid_days'=>30,'activation'=>'first_payment'];$benefits->configure($admin,$personal,$benefit);$benefits->configure($admin,$freshPaid,$benefit);
$quote=$enrollments->reserve($student,$personal);$invoice=$billing->current($quote)->sole();$contract=\App\Models\EnrollmentConsultationBenefit::where('enrollment_id',$quote->id)->where('billing_version',$quote->billing_version)->sole();
$frozen=function()use($quote,$contract){return hash('sha256',json_encode([$quote->fresh()->getAttributes(),$quote->installments()->get()->map->getAttributes()->all(),$contract->fresh()->getAttributes()],JSON_THROW_ON_ERROR));};$frozenBefore=$frozen();
$benefits->configure($admin,$personal,[...$benefit,'revision'=>1,'sessions'=>3,'title'=>'Software-only next edition']);
$plan=['expected_revision'=>0,'installment_enabled'=>true,'manual_payment_enabled'=>true,'parts'=>[['amount'=>40000,'due_date'=>null],['amount'=>60000,'due_date'=>now()->addMonth()->toDateString()]]];app(\App\Http\Controllers\BillingPlanController::class)->update($request('/admin/cohorts/'.$personal->id.'/billing',$plan,$admin),$personal);
$again=$enrollments->reserve($student,$personal);if($again->id!==$quote->id||$frozen()!==$frozenBefore||$contract->definition['sessions']!==2){throw new RuntimeException('Existing production quote or benefit was repriced.');}
$billing->attempt($student,$invoice);$newPaymentQuote=$enrollments->reserve($student,$newInvoiceCohort);$newInvoice=$billing->current($newPaymentQuote)->sole();$expired=$enrollments->reserve($student,$renew);$expired->update(['expires_at'=>now()->subMinute()]);
$captured=\App\Models\Payment::create(['user_id'=>$student->id,'enrollment_id'=>$quote->id,'installment_id'=>$invoice->id,'amount'=>1000000,'method'=>'gateway','status'=>'review_required','reference'=>'SOFTWARE-ONLY-ROLLBACK72','captured_at'=>now(),'paid_at'=>now()]);
$receiptPayment=\App\Models\Payment::create(['user_id'=>$student->id,'enrollment_id'=>$quote->id,'installment_id'=>$invoice->id,'amount'=>1000000,'method'=>'manual','status'=>'pending_review']);$receipt=\App\Models\PaymentReceipt::create(['payment_id'=>$receiptPayment->id,'file_path'=>'software-only-placeholder-not-a-real-bank-document/course72','file_name'=>'software-only-placeholder.pdf','file_hash'=>hash('sha256','SOFTWARE-ONLY-PLACEHOLDER72'),'destination'=>'6037991234567890','transfer_reference'=>'SOFTWAREONLY72RECEIPT','transfer_date'=>now()->toDateString()]);$review=['action'=>'approve','expected_review_version'=>0,'verified_amount'=>100000,'statement_verified'=>true,'review_note'=>'Software-only rollback72'];
$orgData=['name'=>'Software-only rollback72 organization','type'=>'company','phone'=>$manager->phone];app(\App\Http\Controllers\OrganizationController::class)->store($request('/admin/organizations',$orgData,$admin));$org=\App\Models\Organization::where('name',$orgData['name'])->sole();$membership->assignManager($admin,$org,$coach->phone,null);
$membership->invite($manager,$org,$student->phone);$accepted=\App\Models\OrganizationInvitation::where('organization_id',$org->id)->where('phone',$student->phone)->sole();$membership->respond($student,$accepted,1,'accept');$membership->invite($manager,$org,'989121234599');$pending=\App\Models\OrganizationInvitation::where('organization_id',$org->id)->where('phone','989121234599')->sole();
$uploaded=function(string $content,string $name)use(&$temporary){$path=tempnam(sys_get_temp_dir(),'kardorost-auth72-');if($path===false){throw new RuntimeException('Software fixture temporary file unavailable.');}$temporary[]=$path;file_put_contents($path,$content);return new \Illuminate\Http\UploadedFile($path,$name,null,null,true);};
$csv=$uploaded("phone\n989121234598\n",'software-only72.csv');$uncommitted=$membership->preview($manager,$org,$csv);$committed=$membership->preview($manager,$org,$uploaded("phone\n989121234597\n",'software-only72-committed.csv'));$membership->commit($manager,$committed);
$quota=['revision'=>0,'seat_limit'=>3,'expires_on'=>now()->addMonth()->toDateString(),'active'=>true,'contract_reference'=>'SOFTWARE-ONLY-QUOTA72'];$license=$seats->configure($admin,$org,$group,$quota);$newLicense=$seats->configure($admin,$org,$newGroup,$quota);$seats->enroll($manager,$org,$license,1,[$student->id]);
$org2=\App\Models\Organization::create(['name'=>'Software-only rollback72 second root','type'=>'company','status'=>'active']);$key=fn($user)=>$org->members()->where('users.id',$user->id)->firstOrFail()->pivot->membership_key;$studentKey=$key($student);$managerKey=$key($manager);$coachKey=$key($coach);
$file=$uploaded("%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-BANK-TRANSFER72\n%%EOF\n",'software-only72.pdf');$expected=$fingerprint();
\Illuminate\Support\Facades\DB::table('users')->where('id',$admin->id)->update(['is_active'=>false]);
$denied(fn()=>$benefits->configure($admin,$personal,[...$benefit,'revision'=>2]));$denied(fn()=>$benefits->retry($admin,$contract));$denied(fn()=>$billing->resolve($captured,$admin));$denied(fn()=>$receipts->review($admin,$receipt,$review));$denied(fn()=>$receipts->review($admin,$receipt,[...$review,'action'=>'reject']));$denied(fn()=>app(\App\Http\Controllers\BillingPlanController::class)->update($request('/admin/cohorts/'.$personal->id.'/billing',[...$plan,'expected_revision'=>1],$admin),$personal));$denied(fn()=>app(\App\Http\Controllers\CourseController::class)->store($request('/admin/courses',[...$courseData,'title'=>'Software-only denied course72'],$admin)));
$denied(fn()=>app(\App\Http\Controllers\OrganizationController::class)->store($request('/admin/organizations',[...$orgData,'name'=>'Software-only denied organization72'],$admin)));$denied(fn()=>$membership->assignManager($admin,$org,$student->phone,$studentKey));$denied(fn()=>$membership->assignManager($admin,$org,$coach->phone,$coachKey));$denied(fn()=>$seats->configure($admin,$org2,$group,$quota));$denied(fn()=>$seats->configure($admin,$org,$group,[...$quota,'revision'=>1],$license));$denied(fn()=>$membership->endMembership($admin,$org,$student,$studentKey,'Software-only removal','remove'));$denied(fn()=>$membership->endMembership($admin,$org,$manager,$managerKey,'Software-only manager change','revoke_manager'));
\Illuminate\Support\Facades\DB::table('users')->where('id',$admin->id)->update(['is_active'=>true]);if($fingerprint()!==$expected){throw new RuntimeException('Denied production administrator changed course or organization state.');}
\Illuminate\Support\Facades\DB::table('users')->where('id',$manager->id)->update(['is_active'=>false]);
$denied(fn()=>$membership->invite($manager,$org,'989121234596'));$denied(fn()=>$membership->change($manager,$pending,1,'revoke'));$denied(fn()=>$membership->change($manager,$pending,1,'renew'));$denied(fn()=>$membership->preview($manager,$org,$csv));$denied(fn()=>$membership->commit($manager,$uncommitted));$denied(fn()=>$membership->commit($manager,$committed));$denied(fn()=>$membership->endMembership($manager,$org,$student,$studentKey,'Software-only removal','remove'));$denied(fn()=>$seats->enroll($manager,$org,$newLicense,1,[$student->id]));$denied(fn()=>$seats->enroll($manager,$org,$license,1,[$student->id]));
\Illuminate\Support\Facades\DB::table('users')->where('id',$manager->id)->update(['is_active'=>true]);if($fingerprint()!==$expected){throw new RuntimeException('Denied production manager changed invitations, imports or seats.');}
\Illuminate\Support\Facades\DB::table('users')->where('id',$student->id)->update(['is_active'=>false]);
foreach([$freshPaid,$free,$personal,$group,$renew]as $target){$denied(fn()=>$enrollments->reserve($student,$target));}$denied(fn()=>$billing->attempt($student,$newInvoice));$denied(fn()=>$billing->attempt($student,$invoice));$denied(fn()=>$receipts->submit($student,$invoice,$file,'SOFTWAREONLYROLLBACK72',now()->toDateString()));$denied(fn()=>$membership->endMembership($student,$org,$student,$studentKey,'Software-only departure','leave'));
\Illuminate\Support\Facades\DB::table('users')->where('id',$student->id)->update(['is_active'=>true]);if($fingerprint()!==$expected){throw new RuntimeException('Denied production student changed signup, payment, receipt or membership.');}
\Illuminate\Support\Facades\DB::table('users')->where('id',$coach->id)->update(['is_active'=>false]);$again=$enrollments->reserve($student,$personal);if($again->id!==$quote->id||$frozen()!==$frozenBefore){throw new RuntimeException('Held production quote changed when future coach became unavailable.');}$blocked=false;try{$enrollments->reserve($student,$freshPaid);}catch(\Illuminate\Validation\ValidationException $exception){$blocked=array_keys($exception->errors())===['benefit'];}if(!$blocked){throw new RuntimeException('Unavailable future coach accepted a new benefit quote.');}
\Illuminate\Support\Facades\DB::table('users')->where('id',$coach->id)->update(['is_active'=>true]);if($fingerprint()!==$expected){throw new RuntimeException('Production frozen quote or blocked signup changed state.');}
}finally{\Illuminate\Support\Facades\DB::rollBack();foreach($temporary as $path){if(is_file($path)){unlink($path);}}}
if($fingerprint()!==$before||$checks!==32){throw new RuntimeException('Production course/organization fixture did not roll back completely.');}
echo 'THIRTY_TWO_PRODUCTION_COURSE_ORGANIZATION_REFUSALS_FROZEN_QUOTE_AND_FIXTURE_ROWS_ROLLED_BACK';
"""
