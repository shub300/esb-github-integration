@extends('layouts.master')

@section('head-content')

<style>

</style>

@endsection

@section('title', 'Mapping')

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
  <li class="breadcrumb-item active">Mapping</li>
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
		
		.boxStyle1 img{max-width: 80%;}
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
		.labelStyle1{color: #b3b3b3;
    padding: 8px;
    border: 1px solid;
    border-radius: 4px;}
	
	.feather, [data-feather] {
    height: 1.5rem;
    width: 1.5rem;
	}
	
	.b-1{border:1px solid #7367F0;}
	.removeBtn{cursor:pointer;color:#ff0000;}
	</style>
@endpush

@section('page-content')


        <div class="row">
		
		    <div class="col-xl-12 col-md-12 col-12">
				  <div class="card boxStyle1">
					<div class="card-header flex-column align-items-center pb-0">
					
						<div class="row justify-content-center align-items-center py-1 p-relative">
						  <div class="connect-app"><span></span></div>
						  <div class="col mb-xl-0 text-center">
							 <div class="app-icon" data-app="1" data-id="" data-icon="" data-text="">
							   <img class="icon" src="https://esb.apiworx.net/integration/public/img/woccomerce.png">
							 </div>
						  </div>
						  <div class="col mb-xl-0 text-center"> 
							 <div class="app-icon" data-app="2" data-id="" data-icon="" data-text="">
							   <img class="icon" src="https://esb.apiworx.net/integration/public/img/slack.png">
							 </div>
						  </div>
						</div>
					  
					  <h3 class="fw-bolder mt-1">WooComm + Slack</h3>
					  <p class="card-text mb-1"><small>Reference site about Lorem Ipsum, giving information on its origins, as well as a random Lipsum generator.</small></p>
					  <!--a href="step3"><button type="button" class=" mb-1 btn btn-primary waves-effect waves-float waves-light">View Details</button></a-->
					</div>
					<div class="card-body p-0 pb-2">
					  <div id="goal-overview-chart"></div>
					  <div class="row border-top-blue text-center mx-0">
					  
						<div class="col-12 py-1">
						  <h3 class="fw-bolder mb-0">Mappings</h3>
						</div>
						
					  </div>
					  
					  <div class="mappin-wrapper ">
						  <div class="row text-center mx-0 mb-1 justify-content-center align-items-center">
							  <div class="col-xl-3 col-md-4 col-sm-12">
								 <h5 class="m-0 w-100 labelStyle1">Email</h5>
							  </div>
							  <div class="col-auto"><i data-feather="arrow-right"></i></div>
							  <div class="col-xl-3 col-md-4 col-sm-12">
								 <select class="form-control b-1">
								   <option>sfdf@gmail.com</option>
								   <option>dae@gmail.com</option>
								   <option>asfd@gmail.com</option>
								   <option>qwafd@gmail.com</option>
								 <select>
							  </div>
						  </div>
						  <div class="row text-center mx-0 mb-1 justify-content-center align-items-center">
							  <div class="col-xl-3 col-md-4 col-sm-12">
								 <h5 class="m-0 w-100 labelStyle1">Username</h5>
							  </div>
							  <div class="col-auto"><i data-feather="arrow-right"></i></div>
							  <div class="col-xl-3 col-md-4 col-sm-12">
								 <select class="form-control b-1">
								   <option>sfdf</option>
								   <option>sdf</option>
								   <option>afd</option>
								   <option>afd</option>
								 <select>
							  </div>
						  </div>
					  </div>
					  
					  
					  <div class="mappin-wrapper dynemicMapping">
						  <div class="row text-center mx-0 mb-1 justify-content-center align-items-center D_MainItem">
						     <div class="col-auto" style="opacity:0"><i style="width:21px;height:1px;display:block;"></i></div>
							  <div class="col-xl-3 col-md-4 col-sm-12">
								 <select class="form-control b-1">
								   <option>sfdf@gmail.com</option>
								   <option>dae@gmail.com</option>
								   <option>asfd@gmail.com</option>
								   <option>qwafd@gmail.com</option>
								 <select>
							  </div>
							  <div class="col-auto"><i data-feather="arrow-right"></i></div>
							  <div class="col-xl-3 col-md-4 col-sm-12">
								 <select class="form-control b-1">
								   <option>sfdf@gmail.com</option>
								   <option>dae@gmail.com</option>
								   <option>asfd@gmail.com</option>
								   <option>qwafd@gmail.com</option>
								 <select>
							  </div>
							  <div class="col-auto removeBtn"><i data-feather="trash"></i></div>
						  </div>
					  </div>
					  <div class="row justify-content-center align-items-center">
					     <button class="btn btn-outline-success addBtn"><i class="fa fa-plus" ></i> Add New</button>
					  </div>
					  
					  
					</div>
				  </div> 
			</div>
			
			
		</div>
		
		
		
			
			
			
@endsection
@push('page-script')
<script>
   
   jQuery('.addBtn').click(function(e){
		  
		  const tabId = jQuery('.D_MainItem').html();
		    jQuery( ".dynemicMapping" ).append( '<div class="row text-center mx-0 mb-1 justify-content-center align-items-center">'+tabId+'</div>' );
		 
		 
	});
	
	jQuery(document).on('click','.removeBtn', function(e){
		jQuery(this).parent().remove();
	});

</script>
@endpush
