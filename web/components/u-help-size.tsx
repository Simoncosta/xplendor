import React from "react";

export default function UHelpSize() {
    return (
        <div className="w-full h-10 grid grid-cols-12">
            <div className="w-full h-full rounded-md bg-black sm:bg-danger md:bg-primary lg:bg-warning xl:bg-secondary 2xl:bg-success col-span-12
            flex items-center justify-center
            ">
                <span className="text-white font-bold hidden 2xl:block">2XL</span>
                <span className="text-white font-bold hidden xl:block 2xl:hidden">XL</span>
                <span className="text-white font-bold hidden lg:block xl:hidden">LG</span>
                <span className="text-white font-bold hidden md:block lg:hidden xl:hidden">MD</span>
                <span className="text-white font-bold hidden sm:block md:hidden lg:hidden xl:hidden">SM</span>
                <span className="text-white font-bold block sm:hidden md:hidden lg:hidden xl:hidden ">XS</span>
            </div>
        </div>
    )
}