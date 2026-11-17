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
