@extends('layouts.master')

@section('head-content')

<style>

</style>

@endsection

@section('title', 'Integration Step2')

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
  <li class="breadcrumb-item active">Integration Step2</li>
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
		
		.boxStyle1 img{width: 100%;}
		.boxStyle1:hover{    box-shadow: 0 4px 24px 0 rgb(94 80 238 / 50%);}
		.oneLineText{    white-space: nowrap;overflow: hidden;text-overflow: ellipsis;}
		.border-top-blue {
            border-top: 1px solid #c6c1f9!important;
        }
		.border-end-blue{border-right: 1px solid #c6c1f9!important;}
		
		
		
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
		
		
	</style>
@endpush

@section('page-content')
        <div class="row">
		  
			<!--<div class="col-xl-4 col-md-4 col-12">
			  <div class="card boxStyle1">
				<div class="card-header flex-column align-items-center pb-0">
					<div class="flex-row">
					  <div class="avatar bg-light-primary p-50 m-0">
						<div class="avatar-content">
						  <i class="fab fa-wordpress" style="font-size: 25px;"></i>
						</div>
					  </div>
					  <span>+</span>
					  <div class="avatar bg-light-primary p-50 m-0">
						<div class="avatar-content">
						  <i class="fab fa-bootstrap" style="font-size: 25px;"></i>
						</div>
					  </div>
				  </div>
				  <h3 class="fw-bolder mt-1">Wordpress + Brightpearl</h3>
				  <p class="card-text mb-1"><small>Reference site about Lorem Ipsum, giving information on its origins, as well as a random Lipsum generator.</small></p>
				  <a href="step3"><button type="button" class=" mb-1 btn btn-primary waves-effect waves-float waves-light">View Details</button></a>
				</div>
				<div class="card-body p-0">
				  <div id="goal-overview-chart"></div>
				  <div class="row border-top-blue text-center mx-0">
					<div class="col-6 border-end-blue py-1">
					  <h3><a href="#"><h5 class="fw-bolder mb-0">Completed</h3></a>
					</div>  
					<div class="col-6 py-1">
					  <h3><a href="#"><h5 class="fw-bolder mb-0">Progress</h3></a>
					</div>
				  </div>
				</div>
			  </div>
			</div>
			
			<div class="col-xl-4 col-md-4 col-12">
			  <div class="card boxStyle1">
				<div class="card-header flex-column align-items-center pb-0">
					<div class="flex-row">
					  <div class="avatar bg-light-primary p-50 m-0">
						<div class="avatar-content">
						  <i class="fab fa-wordpress" style="font-size: 25px;"></i>
						</div>
					  </div>
					  <span>+</span>
					  <div class="avatar bg-light-primary p-50 m-0">
						<div class="avatar-content">
						  <i class="fab fa-bootstrap" style="font-size: 25px;"></i>
						</div>
					  </div>
				  </div>
				  <h3 class="fw-bolder mt-1">Wordpress + Brightpearl</h3>
				  <p class="card-text mb-1"><small>Reference site about Lorem Ipsum, giving information on its origins, as well as a random Lipsum generator.</small></p>
				  <a href="step3"><button type="button" class=" mb-1 btn btn-primary waves-effect waves-float waves-light">View Details</button></a>
				</div>
				<div class="card-body p-0">
				  <div id="goal-overview-chart"></div>
				  <div class="row border-top-blue text-center mx-0">
					<div class="col-6 border-end-blue py-1">
					  <h3><a href="#"><h5 class="fw-bolder mb-0">Completed</h3></a>
					</div>  
					<div class="col-6 py-1">
					  <h3><a href="#"><h5 class="fw-bolder mb-0">Progress</h3></a>
					</div>
				  </div>
				</div>
			  </div>
			</div>
			-->
			
			
			<div class="col-xl-4 col-md-4 col-12">
			  <div class="card boxStyle1">
				<div class="card-header flex-column align-items-center pb-0">
				
					<div class="row justify-content-center align-items-center py-1 p-relative">
					  <div class="connect-app"><span></span></div>
					  <div class="col mb-xl-0 text-center">
						 <div class="app-icon" data-app="1" data-id="" data-icon="" data-text="">
						   <img class="icon" src="https://esb.apiworx.net/integration/public/img/slack.png" >
						 </div>
					  </div>
					  <div class="col mb-xl-0 text-center"> 
						 <div class="app-icon" data-app="2" data-id="" data-icon="" data-text="">
						   <img class="icon" src="https://esb.apiworx.net/integration/public/img/slack.png"  >
						 </div>
					  </div>
					</div>
				  
				  <h3 class="fw-bolder mt-1">Wordpress + Brightpearl</h3>
				  <p class="card-text mb-1"><small>Reference site about Lorem Ipsum, giving information on its origins, as well as a random Lipsum generator.</small></p>
				  <a href="step3"><button type="button" class=" mb-1 btn btn-primary waves-effect waves-float waves-light">View Details</button></a>
				</div>
				<div class="card-body p-0">
				  <div id="goal-overview-chart"></div>
				  <div class="row border-top-blue text-center mx-0">
					<!--<div class="col-6 border-end-blue py-1">
					  <h3><a href="#"><h5 class="fw-bolder mb-0">Completed</h3></a>
					</div>  -->
					<div class="col-12 py-1">
					  <h3><a href="#"><h5 class="fw-bolder mb-0">Progress</h3></a>
					</div>
				  </div>
				</div>
			  </div> 
			</div>
			
			<div class="col-xl-4 col-md-4 col-12">
			  <div class="card boxStyle1">
				<div class="card-header flex-column align-items-center pb-0">
				
					<div class="row justify-content-center align-items-center py-1 p-relative">
					  <div class="connect-app"><span></span></div>
					  <div class="col mb-xl-0 text-center">
						 <div class="app-icon" data-app="1" data-id="" data-icon="" data-text="">
						   <img class="icon" src="https://esb.apiworx.net/integration/public/img/slack.png" >
						 </div>
					  </div>
					  <div class="col mb-xl-0 text-center"> 
						 <div class="app-icon" data-app="2" data-id="" data-icon="" data-text="">
						   <img class="icon" src="https://esb.apiworx.net/integration/public/img/slack.png"  >
						 </div>
					  </div>
					</div>
				  
				  <h3 class="fw-bolder mt-1">Wordpress + Brightpearl</h3>
				  <p class="card-text mb-1"><small>Reference site about Lorem Ipsum, giving information on its origins, as well as a random Lipsum generator.</small></p>
				  <a href="step3"><button type="button" class=" mb-1 btn btn-primary waves-effect waves-float waves-light">View Details</button></a>
				</div>
				<div class="card-body p-0">
				  <div id="goal-overview-chart"></div>
				  <div class="row border-top-blue text-center mx-0">
					<!--<div class="col-6 border-end-blue py-1">
					  <h3><a href="#"><h5 class="fw-bolder mb-0">Completed</h3></a>
					</div>  -->
					<div class="col-12 py-1">
					  <h3><a href="#"><h5 class="fw-bolder mb-0">Progress</h3></a>
					</div>
				  </div>
				</div>
			  </div> 
			</div>
			
			<div class="col-xl-4 col-md-4 col-12">
			  <div class="card boxStyle1">
				<div class="card-header flex-column align-items-center pb-0">
				
					<div class="row justify-content-center align-items-center py-1 p-relative">
					  <div class="connect-app"><span></span></div>
					  <div class="col mb-xl-0 text-center">
						 <div class="app-icon" data-app="1" data-id="" data-icon="" data-text="">
						   <img class="icon" src="https://esb.apiworx.net/integration/public/img/slack.png" >
						 </div>
					  </div>
					  <div class="col mb-xl-0 text-center"> 
						 <div class="app-icon" data-app="2" data-id="" data-icon="" data-text="">
						   <img class="icon" src="https://esb.apiworx.net/integration/public/img/slack.png"  >
						 </div>
					  </div>
					</div>
				  
				  <h3 class="fw-bolder mt-1">Wordpress + Brightpearl</h3>
				  <p class="card-text mb-1"><small>Reference site about Lorem Ipsum, giving information on its origins, as well as a random Lipsum generator.</small></p>
				  <a href="step3"><button type="button" class=" mb-1 btn btn-primary waves-effect waves-float waves-light">View Details</button></a>
				</div>
				<div class="card-body p-0">
				  <div id="goal-overview-chart"></div>
				  <div class="row border-top-blue text-center mx-0">
					<!--<div class="col-6 border-end-blue py-1">
					  <h3><a href="#"><h5 class="fw-bolder mb-0">Completed</h3></a>
					</div>  -->
					<div class="col-12 py-1">
					  <h3><a href="#"><h5 class="fw-bolder mb-0">Progress</h3></a>
					</div>
				  </div>
				</div>
			  </div> 
			</div>
			
			
		</div>
		
		
		
		<!-- My Apps Section -->
			<section id="my-apps">
			
			  <div class="row match-height ">
			    
				<!-- workflow Card -->
				<div class="col-xl-4 col-md-4 col-sm-6 col-12 " >
				  <div class="card card-statistics myApp-item" id="">
					
					<div class="card-body statistics-body ">
					    <span></span>
					    <div class="row justify-content-center align-items-center py-1 p-relative">
						  <div class="connect-app"><span></span></div>
					      <div class="col mb-xl-0 text-center">
						     <div class="app-icon" data-app="1" data-id="" data-icon="" data-text="">
							   <img class="icon" src="https://esb.apiworx.net/integration/public/img/slack.png" >
							 </div>
						  </div>
						  <div class="col mb-xl-0 text-center"> 
						     <div class="app-icon" data-app="2" data-id="" data-icon="" data-text="">
							   <img class="icon" src="https://esb.apiworx.net/integration/public/img/slack.png"  >
							 </div>
						  </div>
						</div>
                        <div class="row justify-content-center ">						
						  <div class="col-xl-12 col-sm-12 col-12 mb-xl-0 text-center">
						      <h4 class="my-app-title dark-text">Google Doc + Gmail</h4>
						      <div class="actionBtn" >
								 <a href="step3"><button type="button" class="btn btn-primary waves-effect waves-float waves-light loading-button w-100" data-text="Apply Now" data-clicked="Connecting . . ">
									<span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class="text">View Details</span>
								 </button></a>
						      </div>
						   </div>
					    </div>
					 
					</div>
				  </div>
				</div>
				
				<!-- workflow Card -->
				<div class="col-xl-4 col-md-4 col-sm-6 col-12 " >
				  <div class="card card-statistics myApp-item" id="">
					
					<div class="card-body statistics-body ">
					
					    <div class="row justify-content-center align-items-center py-1 p-relative">
						  <div class="connect-app"><span></span></div>
					      <div class="col mb-xl-0 text-center">
						     <div class="app-icon" data-app="1" data-id="" data-icon="" data-text="">
							   <img class="icon" src="https://esb.apiworx.net/integration/public/img/shopify.png" >
							 </div>
						  </div>
						  <div class="col mb-xl-0 text-center">
						     <div class="app-icon" data-app="2" data-id="" data-icon="" data-text="">
							   <img class="icon" src="https://esb.apiworx.net/integration/public/img/shopify.png"  >
							 </div>
						  </div>
						</div>
                        <div class="row justify-content-center ">						
						  <div class="col-xl-12 col-sm-12 col-12 mb-xl-0 text-center">
						      <h4 class="my-app-title dark-text">Google Contact + Gmail</h4>
						      <div class="actionBtn" >
								 <a href="step3"><button type="button" class="btn btn-primary waves-effect waves-float waves-light loading-button w-100" data-text="Apply Now" data-clicked="Connecting . . ">
									<span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class="text">View Details</span>
								 </button></a>
						      </div>
						   </div>
					    </div>
					 
					</div>
				  </div>
				</div>
				
				<!-- workflow Card -->
				<div class="col-xl-4 col-md-4 col-sm-6 col-12 " >
				  <div class="card card-statistics myApp-item" id="">
					
					<div class="card-body statistics-body ">
					
					    <div class="row justify-content-center align-items-center py-1 p-relative">
						  <div class="connect-app"><span></span></div>
					      <div class="col mb-xl-0 text-center">
						     <div class="app-icon" data-app="1" data-id="" data-icon="" data-text="">
							   <img class="icon" src="https://esb.apiworx.net/integration/public/img/woccomerce.png" >
							 </div>
						  </div>
						  <div class="col mb-xl-0 text-center">
						     <div class="app-icon" data-app="2" data-id="" data-icon="" data-text="">
							   <img class="icon" src="https://esb.apiworx.net/integration/public/img/woccomerce.png"  >
							 </div>
						  </div>
						</div>
                        <div class="row justify-content-center ">						
						  <div class="col-xl-12 col-sm-12 col-12 mb-xl-0 text-center">
						      <h4 class="my-app-title dark-text">Google Sheet + Gmail</h4>
						      <div class="actionBtn" >
								 <a href="step3"><button type="button" class="btn btn-primary waves-effect waves-float waves-light loading-button w-100" data-text="Apply Now" data-clicked="Connecting . . ">
									<span class="spinner-border spinner-border-sm loading-icon" role="status" aria-hidden="true"></span> <span class="text">View Details</span>
								 </button></a>
						      </div>
						   </div>
					    </div>
					 
					</div>
				  </div>
				</div>
				
				
				
			  </div>

			 
			 </section>
			 
			 
			 
			 <!-- Workflow Section -->
			<section id="dashboard-ecommerce">
			
			 <div class="col-md-12 my-1">
				<div class="group-area">
				  <h2>Recommended workflows for you.</h2>
				</div>
			  </div>
			
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
									<img src="https://esb.apiworx.net/integration/public/img/woccomerce.png" class="avatar-icon">
								  </div>
								  <div class="avatar-content">
									<img src="https://esb.apiworx.net/integration/public/img/slack.png" class="avatar-icon">
								  </div>
								</div>
								
								<div class="media-body my-auto">
								  <h4 class="font-weight-bolder mb-0 dark-text">Save new Gmail attachments to Google Drive</h4>
								  <p class="card-text font-small-3 mb-0">Google Calender + Google Sheet</p>
								</div>
								
								<div class="action">
								  <button type="button" class="btn btn-primary waves-effect waves-float waves-light"> Try it</button>
								</div>
							  </div>
							</div>
						  </div>
						</div>
					  </div>
					  
					  <div class="card work-item-card">
						<div class="card-body work-item">
						  <div class="row">
							<div class="col-xl-12 col-sm-12 col-12 mb-2 mb-xl-0">
							  <div class="media">
								<div class="avatar bg-light-primary mr-2">
								  <div class="avatar-content">
									<img src="https://esb.apiworx.net/integration/public/img/woccomerce.png" class="avatar-icon">
								  </div>
								  <div class="avatar-content">
									<img src="https://esb.apiworx.net/integration/public/img/slack.png" class="avatar-icon">
								  </div>
								</div>
								
								<div class="media-body my-auto">
								  <h4 class="font-weight-bolder mb-0 dark-text">Save new Gmail attachments to Google Drive</h4>
								  <p class="card-text font-small-3 mb-0">Google Calender + Google Sheet</p>
								</div>
								
								<div class="action">
								  <button type="button" class="btn btn-primary waves-effect waves-float waves-light"> Try it</button>
								</div>
							  </div>
							</div>
						  </div>
						</div>
					  </div>
					  
					   <div class="card work-item-card">
						<div class="card-body work-item">
						  <div class="row">
							<div class="col-xl-12 col-sm-12 col-12 mb-2 mb-xl-0">
							  <div class="media">
								<div class="avatar bg-light-primary mr-2">
								  <div class="avatar-content">
									<img src="https://esb.apiworx.net/integration/public/img/woccomerce.png" class="avatar-icon">
								  </div>
								  <div class="avatar-content">
									<img src="https://esb.apiworx.net/integration/public/img/slack.png" class="avatar-icon">
								  </div>
								</div>
								
								<div class="media-body my-auto">
								  <h4 class="font-weight-bolder mb-0 dark-text">Save new Gmail attachments to Google Drive</h4>
								  <p class="card-text font-small-3 mb-0">Google Calender + Google Sheet</p>
								</div>
								
								<div class="action">
								  <button type="button" class="btn btn-primary waves-effect waves-float waves-light"> Try it</button>
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
