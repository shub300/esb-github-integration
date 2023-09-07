@extends('layouts.master')

@section('head-content')

<style>

</style>

@endsection

@section('title', 'Complete the steps to install the inregration.')

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
  <li class="breadcrumb-item active">Complete the steps to install the inregration.</li>  
@endsection

@push('page-style')
	<style>
		.accoBtn{
			width: 25px;
			height: 25px;
			color: #6e6b7b;
			cursor:pointer;
			transition: transform .5s;
		}
		.setup-icon-box {
			padding-right:10px; 
		}
		.dhide{
			visibility: hidden;
		}
		

		
		
		 .app-icon .icon{
			padding: 10px;
			border: 1px dashed #aba9a9;
			border: 1px dashed #7367f0;
            border-radius: 10px;

			width:100%;
			max-width:120px;
			background:#fff;
		}
		.my-app-title{
			margin-bottom: 15px;
		}
		.connect-app{    
		    position: absolute;
			width: 50%;
			right: 25%;
			top: 50%;
			border-top: 1px dashed #7367f0;
		}
		.p-relative{position:relative;}
		
		.myApp-item .app-icon .icon { }
		.myApp-item  { background: linear-gradient( 45deg , #dcdaff, #c2bfef); overflow:hidden;} 
		
		.myApp-item .card-body>span{
			 /*content:'';
			 width:400px;
			 height:400px;
			 left:-200px;
			 bottom:-200px;
			 border-radius:50%;
			 position:absolute;
             background: rgb(94 80 238 / 18%);
			 width: 180px;*/
			 
			/*transform: skewX(20deg) translateX(50px);
			position: absolute;
			right: 0;
			height: inherit;
			background-image: linear-gradient(to bottom, #ff6767, #ff4545);
			box-shadow: 0 0 10px 0px rgb(0 0 0 / 50%);*/
		}
		
		.work-item .media>.avatar {
			border: 1px solid #7367f0;
			border-radius: 50%;
		}
		.work-item-card:hover {
			box-shadow: 0 4px 24px 0 rgb(94 80 238 / 50%);
		}
		
		.work-item .avatar .avatar-content .avatar-icon {
			width: 38px;
			height: 38px;
		}
		.avatar .avatar-content {
			width: 38px;
			height: 38px;
		}
		.work-item .avatar-content h2 {color:#7367f0;}
		
		.mw-120{min-width:165px;}
		
		.v-line:after{    
		   content: "";
			position: absolute;
			width: 2px;
			height: 20px;
			background: #7367f0;
			top: -17px;
			z-index: -1;
			margin-left: 22px;
		}
		
		
		.card.work-item-card{
			border-top-left-radius: 40px;
            border-bottom-left-radius: 40px;
		}
	</style>
@endpush

@section('page-content')
        
		
		
		
			 
			 
			 
			 <!-- Workflow Section -->
			<section id="dashboard-ecommerce">
			
			  <!--<div class="col-md-12 my-1">
				<div class="group-area">
				  <h2>Recommended workflows for you.</h2>
				</div>
			  </div>-->
			
			  <div class="row match-height">
			    
				<!-- workflow Card -->
				<div class="col-xl-12 col-md-12 col-12">
				
				
					  <div class="card work-item-card">
						<div class="card-body work-item">
						  <div class="row">
							<div class="col-xl-12 col-sm-12 col-12 mb-2 mb-xl-0">
							  <div class="media">
								<div class="avatar bg-light-primary mr-2">
								  <div class="avatar-content">
									 <h2 class='mb-0'>1</h2>
								  </div>
								</div>
								
								<div class="media-body my-auto">
								  <h4 class="font-weight-bolder mb-0 dark-text">Save new Gmail attachments to Google Drive</h4>
								  <p class="card-text font-small-3 mb-0">Google Calender + Google Sheet</p>
								</div>
								
								<div class="action">
								  <button type="button" class="btn btn-primary waves-effect waves-float waves-light mw-120 connect-now loading-button" data-text='Configure' data-clicked='Connecting . . '>
									<span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Configure</span></button>
								</div>
							  </div>
							</div>
						  </div>
						</div>
					  </div>
					  
					  <div class="card work-item-card ">
						<div class="card-body work-item v-line">
						  <div class="row">
							<div class="col-xl-12 col-sm-12 col-12 mb-2 mb-xl-0">
							  <div class="media">
								<div class="avatar bg-light-primary mr-2">
								  <div class="avatar-content">
									 <h2 class='mb-0'>2</h2>
								  </div>
								</div>
								
								<div class="media-body my-auto">
								  <h4 class="font-weight-bolder mb-0 dark-text">Save new Gmail attachments to Google Drive</h4>
								  <p class="card-text font-small-3 mb-0">Google Calender + Google Sheet</p>
								</div>
								
								<div class="action">
								  <button type="button" class="btn btn-primary waves-effect waves-float waves-light mw-120 connect-now loading-button" data-text='Configure' data-clicked='Connecting . . '>
									<span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Configure</span></button>
								</div>
							  </div>
							</div>
						  </div>
						</div>
					  </div>
					  
					   <div class="card work-item-card">
						<div class="card-body work-item v-line">
						  <div class="row">
							<div class="col-xl-12 col-sm-12 col-12 mb-2 mb-xl-0">
							  <div class="media">
								<div class="avatar bg-light-primary mr-2">
								  <div class="avatar-content">
									 <h2 class='mb-0'>3</h2>
								  </div>
								</div>
								
								<div class="media-body my-auto">
								  <h4 class="font-weight-bolder mb-0 dark-text">Save new Gmail attachments to Google Drive</h4>
								  <p class="card-text font-small-3 mb-0">Google Calender + Google Sheet</p>
								</div>
								
								<div class="action">
								  <button type="button" class="btn btn-primary waves-effect waves-float waves-light mw-120 connect-now loading-button" data-text='Configure' data-clicked='Connecting . . '>
									<span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class='text'> Configure</span></button>
								</div>
							  </div>
							</div>
						  </div>
						</div>
					  </div>
				  
				  
				</div>
				<!--/ Statistics Card -->
			  </div>

			 
			 </section>
			<!-- Dashboard Ecommerce ends -->
			
			
			
@endsection
@push('page-script')
<script>

</script>
@endpush
