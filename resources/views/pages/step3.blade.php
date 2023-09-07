@extends('layouts.master')

@section('head-content')

<style>

</style>

@endsection

@section('title', 'Integration Details')

@section('side-bar')
@include('layouts.menu-bar')
@endsection

@section('breadcrumb')
  <li class="breadcrumb-item active">Integration Details</li>
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
		
		
		  
		#user-profile .profile-header{overflow:hidden}#user-profile .profile-header .profile-img-container{position:absolute;bottom:-2rem;left:2.14rem;z-index:2}#user-profile .profile-header .profile-img-container .profile-img{height:8.92rem;width:8.92rem;border:.357rem solid #FFF;background-color:#FFF;border-radius:.428rem;box-shadow:0 4px 24px 0 rgba(34,41,47,.1)}#user-profile .profile-header .profile-header-nav .navbar{padding:.8rem 1rem}#user-profile .profile-header .profile-header-nav .navbar .navbar-toggler{line-height:0}#user-profile .profile-header .profile-header-nav .navbar .profile-tabs .nav-item i,#user-profile .profile-header .profile-header-nav .navbar .profile-tabs .nav-item svg{margin-right:0}#user-profile #profile-info .profile-star{color:#BABFC7}#user-profile #profile-info .profile-star i.profile-favorite,#user-profile #profile-info .profile-star svg.profile-favorite{fill:#FF9F43;stroke:#FF9F43}#user-profile #profile-info .profile-likes{fill:#EA5455;stroke:#EA5455}#user-profile #profile-info .profile-polls-info .progress{height:.42rem}#user-profile .profile-latest-img{-webkit-transition:all .2s ease-in-out;transition:all .2s ease-in-out}#user-profile .profile-latest-img:hover{-webkit-transform:translateY(-4px) scale(1.2);-ms-transform:translateY(-4px) scale(1.2);transform:translateY(-4px) scale(1.2);z-index:10}#user-profile .profile-latest-img img{margin-top:1.28rem}#user-profile .block-element .spinner-border{border-width:.14rem}@media (max-width:991.98px){#user-profile .profile-latest-img img{width:100%}}@media (min-width:768px){.profile-header-nav .profile-tabs{width:100%;margin-left:13.2rem}}@media (max-width:575.98px){#user-profile .profile-header .profile-img-container .profile-img{height:100px;width:100px}#user-profile .profile-header .profile-img-container .profile-title h2{font-size:1.5rem}}
		
		
		
		.text-black{color:#222;}
		
		
		
		@media (min-width:768px) {
			#user-profile .profile-header .profile-img-container .profile-img {
				height: 13rem;
				width: 13rem;
			}
			.ms-3 {
			margin-left: 3rem!important;
		   }
		}
		
		@media(max-width:767px){
			.header-details{
				flex-direction: column;
				text-align: center;
				margin-top: -38px;
				position:relative !important;
				bottom: unset !important;
                left: unset !important;
                z-index: 2;
			}  
		}
	</style>  
@endpush

@section('page-content')
        
		
<section id="user-profile">		
		<!-- profile header -->
  <div class="row">
    <div class="col-12">
      <div class="card profile-header mb-2 integration-details-header">
        <!-- profile cover photo -->
        <img
          class="card-img-top"
          src="https://esb.apiworx.net/integration/public/img/banner2.jpg"
          alt="User Profile Image"
        />  
		
		
        <!--/ profile cover photo -->

        <div class="position-relative ">
          <!-- profile picture -->
          <div class="profile-img-container d-flex align-items-center header-details">
            <div class="profile-img">
              <img
                src="https://esb.apiworx.net/integration/public/img/icon3.jpg"
                class="rounded img-fluid"
                alt="Card image"
              />
            </div>
            <!-- profile title -->
            <div class="profile-title ms-3">
              <h2 class="text-black">Woo-Shopify</h2>
              <p class="text-black">Integration</p>
            </div>
          </div>
        </div>

        <!-- tabs pill -->
        <div class="profile-header-nav d-none d-md-block d-lg-block d-xl-block">
          <!-- navbar -->
          <nav class="navbar navbar-expand-md navbar-light justify-content-end justify-content-md-between w-100">
            <button
              class="btn btn-icon navbar-toggler"
              type="button"
              data-bs-toggle="collapse"
              data-bs-target="#navbarSupportedContent"
              aria-controls="navbarSupportedContent"
              aria-expanded="false"
              aria-label="Toggle navigation"
            >
              <i data-feather="align-justify" class="font-medium-5"></i>
            </button>

            <!-- collapse  -->
            <div class="collapse navbar-collapse" id="navbarSupportedContent">
              <div class="profile-tabs d-flex justify-content-between flex-wrap mt-1 mt-md-0">
                <!--<ul class="nav nav-pills mb-0">
                  <li class="nav-item">
                    <a class="nav-link fw-bold active" href="#">
                      <span class="d-none d-md-block">Feed</span>
                      <i data-feather="rss" class="d-block d-md-none"></i>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link fw-bold" href="#">
                      <span class="d-none d-md-block">About</span>
                      <i data-feather="info" class="d-block d-md-none"></i>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link fw-bold" href="#">
                      <span class="d-none d-md-block">Photos</span>
                      <i data-feather="image" class="d-block d-md-none"></i>
                    </a>
                  </li>
                  <li class="nav-item">
                    <a class="nav-link fw-bold" href="#">
                      <span class="d-none d-md-block">Friends</span>
                      <i data-feather="users" class="d-block d-md-none"></i>
                    </a>
                  </li>
                </ul>-->
                <!-- edit button -->
				<div></div>  
				
				<div>
					<a href="step4"><button class="btn btn-outline-primary ">
					  <i data-feather="edit" class="d-block d-md-none"></i>
					  <span class="fw-bold d-none d-md-block">Readme</span>
					</button></a>
					<a href="step4"><button class="btn btn-primary ">
					  <i data-feather="edit" class="d-block d-md-none"></i>
					  <span class="fw-bold d-none d-md-block">Install Now</span>
					</button></a>
				</div>
              </div>
            </div>
            <!--/ collapse  -->
          </nav>
          <!--/ navbar -->
        </div>
      </div>
    </div>
  </div>
  <!--/ profile header -->

  <!-- profile info section -->
  </section>
  
  
  <section id="profile-info">
    <div class="row">
      <!-- left profile info section -->
      <div class="col-lg-4 col-12 order-2 order-lg-1">
        <!-- about -->
        <div class="card">
          <div class="card-body">
            <h3 class="mb-75">Integration Details</h3>
			<hr>
			
            <h5 class="mb-75">Joined</h5>
            <p class="card-text">
              Tart I love sugar plum I love oat cake. Sweet ⭐️ roll caramels I love jujubes. Topping cake wafer.
            </p>
            <div class="mt-2">
              <h5 class="mb-75">Joined:</h5>
              <p class="card-text">November 15, 2015</p>
            </div>
            <div class="mt-2">
              <h5 class="mb-75">Lives:</h5>
              <p class="card-text">New York, USA</p>
            </div>
            <div class="mt-2">
              <h5 class="mb-75">Email:</h5>
              <p class="card-text">bucketful@fiendhead.org</p>
            </div>
            <div class="mt-2">
              <h5 class="mb-50">Website:</h5>
              <p class="card-text mb-0">www.pixinvent.com</p>
            </div>
          </div>
        </div>
        <!--/ about -->

      </div>
      <!--/ left profile info section -->

      <!-- center profile info section -->
      <div class="col-lg-8 col-12 order-1 order-lg-2">
        <!-- post 1 -->
        <div class="card">
          <div class="card-body">
		  
		    <h3 class="mb-75">About Integration </h3>
			<hr>
			
			
            <div class="d-flex justify-content-start align-items-center mb-1">
              <!-- avatar -->
        
              <!--/ avatar -->
              <div class="profile-user-info">
                <h4 class="mb-0">Leeanna Alvord</h4>  
              </div>
            </div>   
            <p class="card-text">
              Wonderful Machine· A well-written bio allows viewers to get to know a photographer beyond the work. This
              can make the difference when presenting to clients who are looking for the perfect fit.
            </p>
			
			<ul class="card-text">
              <li>Wonderful Machine· A well-written bio allows viewers to get </li>
			  <li>to know a photographer beyond the work.</li> 
			  <li>This can make the difference when presenting to </li><li>clients who are looking for the perfect fit.</li>
			  <li>Wonderful Machine· A well-written bio allows</li><li> viewers to get to know a photographer beyond the work. </li>
              <li>This can make the difference when presenting to clients who are looking for the perfect fit.</li>
            </ul>
			
			<p class="card-text">
              Wonderful Machine· A well-written bio allows viewers to get to know a photographer beyond the work. This
              can make the difference when presenting to clients who are looking for the perfect fit.
			  Wonderful Machine· A well-written bio allows viewers to get to know a photographer beyond the work. This
              can make the difference when presenting to clients who are looking for the perfect fit.
            </p>
            <!-- post img -->
          
			<div class="d-sm-block d-md-none d-lg-none d-xl-none">
					<button class="btn btn-outline-primary ">
					  
					  <span class="fw-bold ">Readme</span>
					</button>
					<button class="btn btn-primary ">
					  <span class="fw-bold ">Install Now</span>
					</button>
			</div>
        </div>
        <!--/ post 1 -->

      </div>
      <!--/ center profile info section -->
     </div>
	 
	</section>
		
		
			
			
@endsection
@push('page-script')
<script>

</script>
@endpush
